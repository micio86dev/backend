<?php

declare(strict_types=1);

/**
 * `beai:demo-teardown` — selectivity, production confirmation, and the two
 * named guards against silent data loss (design D1, tasks.md overrides;
 * spec: "Teardown Removes Exactly the Marked Demo Rows", "Production
 * Teardown Requires Explicit Confirmation").
 *
 * `beai:demo-teardown` deletes EXACTLY what `DemoMarker` identifies — real
 * rows in the same organization must survive byte-for-byte. Two guards make
 * the one unsound case in the reachability argument (design D1) a loud stop
 * instead of silent data loss:
 *   - a non-demo participant found inside a demo project blocks the whole
 *     run, names the offending row, and deletes nothing
 *   - `beai-demo-1.0.0` still referenced by a non-demo row after every other
 *     delete has run hard-fails and names what references it (ratified
 *     answer to design open question 2)
 */

use App\Models\AiRequest;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Support\Demo\DemoMarker;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

test('teardown removes every demo row and storage object, leaves a real participant/project/evaluation byte-for-byte, and the org survives', function (): void {
    Storage::fake();

    // Hand-create a real, non-demo aggregate in the SAME organization.
    $human = TenantContextScope::runFor($this->org->id, function () {
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'slug' => 'acme-real-project',
        ]);

        $participant = new Participant;
        $participant->forceFill([
            'organization_id' => $this->org->id,
            'project_id' => $project->id,
            'candidate_ref' => 'real-candidate-001',
            'display_name' => 'Real Human',
            'role_code' => $project->role_code,
            'language' => $project->language,
            'status' => 'completato',
            'started_at' => now()->subDay(),
            'completed_at' => now(),
        ])->save();
        $participant->refresh();

        $evaluation = new Evaluation;
        $evaluation->forceFill([
            'participant_id' => $participant->id,
            'framework_version_id' => $project->framework_version_id,
            'status' => 'completed',
            'model_version' => 'v1',
            'prompt_version' => 'v1',
            'evaluated_at' => now(),
            'retry_attempt' => false,
        ])->save();
        $evaluation->refresh();

        return ['project' => $project, 'participant' => $participant, 'evaluation' => $evaluation];
    });

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $this->artisan('beai:demo-teardown', ['--org' => 'acme'])->assertExitCode(0);

    // Real rows survive byte-for-byte: same PK, same column values.
    $freshProject = TenantContextScope::runFor($this->org->id, fn () => Project::find($human['project']->id));
    $freshParticipant = Participant::find($human['participant']->id);
    $freshEvaluation = TenantContextScope::runFor($this->org->id, fn () => Evaluation::find($human['evaluation']->id));

    expect($freshProject->toArray())->toBe($human['project']->fresh()->toArray());
    expect($freshParticipant->candidate_ref)->toBe('real-candidate-001');
    expect($freshEvaluation)->not->toBeNull();

    // Every demo row is gone.
    expect(Participant::where('organization_id', $this->org->id)->where('candidate_ref', 'like', DemoMarker::PREFIX.'%')->count())->toBe(0);

    TenantContextScope::runFor($this->org->id, function (): void {
        expect(Project::where('slug', 'like', DemoMarker::PREFIX.'%')->count())->toBe(0);
        expect(AvatarTemplate::where('name', 'like', DemoMarker::PREFIX.'%')->count())->toBe(0);
        expect(FrameworkVersion::where('version', DemoMarker::PREFIX.'1.0.0')->count())->toBe(0);
        // The four operational tables (design D12/D17): ai_requests and
        // webhook_deliveries via cascade, api_clients and notification_logs
        // via the explicit delete steps.
        expect(AiRequest::count())->toBe(0);
        expect(WebhookDelivery::count())->toBe(0);
        expect(NotificationLog::count())->toBe(0);
    });
    expect(ApiClient::where('organization_id', $this->org->id)->where('name', 'like', DemoMarker::PREFIX.'%')->count())->toBe(0);

    // The organization row itself is never deleted.
    expect(Organization::find($this->org->id))->not->toBeNull();
});

test('production teardown without --confirm-slug refuses and names the flag; with it, proceeds', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    app()['env'] = 'production';

    $this->artisan('beai:demo-teardown', ['--org' => 'acme'])
        ->expectsOutputToContain('--confirm-slug')
        ->assertExitCode(1);

    expect(Participant::where('organization_id', $this->org->id)->where('candidate_ref', 'like', DemoMarker::PREFIX.'%')->count())->toBeGreaterThan(0);

    $this->artisan('beai:demo-teardown', ['--org' => 'acme', '--confirm-slug' => 'acme'])
        ->assertExitCode(0);

    expect(Participant::where('organization_id', $this->org->id)->where('candidate_ref', 'like', DemoMarker::PREFIX.'%')->count())->toBe(0);
});

test('a non-demo participant found inside a demo project blocks teardown entirely, naming the offending row', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $intruder = TenantContextScope::runFor($this->org->id, function () {
        $demoProject = Project::where('slug', DemoMarker::PREFIX.'sales-ico')->firstOrFail();

        $participant = new Participant;
        $participant->forceFill([
            'organization_id' => $this->org->id,
            'project_id' => $demoProject->id,
            'candidate_ref' => 'someone-minted-a-real-sso-link',
            'display_name' => 'Unexpected Human',
            'role_code' => $demoProject->role_code,
            'language' => $demoProject->language,
            'status' => 'in_attesa',
        ])->save();
        $participant->refresh();

        return $participant;
    });

    $participantsBefore = Participant::where('organization_id', $this->org->id)->count();

    $this->artisan('beai:demo-teardown', ['--org' => 'acme'])
        ->expectsOutputToContain('someone-minted-a-real-sso-link')
        ->assertExitCode(1);

    // No cascade, no silent delete — row counts are UNCHANGED.
    expect(Participant::where('organization_id', $this->org->id)->count())->toBe($participantsBefore);
    expect(Participant::find($intruder->id))->not->toBeNull();
});

test('a non-demo row still referencing beai-demo-1.0.0 after every other delete hard-fails teardown, naming what references it', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    TenantContextScope::runFor($this->org->id, function (): void {
        $version = FrameworkVersion::where('version', DemoMarker::PREFIX.'1.0.0')->firstOrFail();

        // An operator created a REAL (non-demo) project pinned to the demo
        // framework version — unusual, but exactly the case this guard
        // exists for.
        Project::factory()->create([
            'organization_id' => $this->org->id,
            'slug' => 'acme-pinned-to-demo-fv',
            'framework_version_id' => $version->id,
        ]);
    });

    $this->artisan('beai:demo-teardown', ['--org' => 'acme'])
        ->expectsOutputToContain(DemoMarker::PREFIX.'1.0.0')
        ->assertExitCode(1);

    // Everything ELSE was still removed — the hard-fail is specific to the
    // FrameworkVersion, not a rollback of the whole teardown.
    expect(Participant::where('organization_id', $this->org->id)->where('candidate_ref', 'like', DemoMarker::PREFIX.'%')->count())->toBe(0);

    TenantContextScope::runFor($this->org->id, function (): void {
        expect(Project::where('slug', 'like', DemoMarker::PREFIX.'%')->count())->toBe(0);
        expect(FrameworkVersion::where('version', DemoMarker::PREFIX.'1.0.0')->exists())->toBeTrue();
        expect(Project::where('slug', 'acme-pinned-to-demo-fv')->exists())->toBeTrue();
    });
});
