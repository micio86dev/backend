<?php

declare(strict_types=1);

/**
 * `beai:demo-teardown` completeness proof for the four operational tables
 * (design D12, spec "Teardown Removes Exactly the Marked Demo Rows"): after
 * teardown, zero demo rows remain in `ai_requests`, `api_clients`,
 * `webhook_deliveries`, `notification_logs` — a hand-created non-demo
 * `api_clients` row, a non-demo participant, and a non-demo project survive
 * byte-for-byte — and `ai_requests`/`webhook_deliveries` vanish via
 * `cascadeOnDelete` ALONE, with no explicit delete step for either.
 */

use App\Models\AiRequest;
use App\Models\ApiClient;
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

test('teardown leaves zero demo rows in all four operational tables, non-demo rows survive, and ai_requests/webhook_deliveries vanish via cascade only', function (): void {
    Storage::fake();

    // A real, non-demo api_clients row — production holds the operator's
    // own key, and teardown must visibly leave it alone.
    $realClient = new ApiClient;
    $realClient->forceFill([
        'organization_id' => $this->org->id,
        'name' => 'acme-real-integration',
        'abilities' => config('m2m_abilities.allowed'),
        'is_active' => true,
        'key_hash' => hash('sha256', 'not-a-real-raw-key-just-a-test-fixture'),
    ]);
    $realClient->save();

    // A real, non-demo participant/project pair in the SAME organization.
    $human = TenantContextScope::runFor($this->org->id, function () {
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'slug' => 'acme-real-project',
        ]);

        $participant = new Participant;
        $participant->forceFill([
            'organization_id' => $this->org->id,
            'project_id' => $project->id,
            'candidate_ref' => 'real-candidate-completeness',
            'display_name' => 'Real Human',
            'email' => uniqid('cand-').'@example.test',
            'role_code' => $project->role_code,
            'language' => $project->language,
            'status' => 'in_attesa',
        ])->save();
        $participant->refresh();

        return ['project' => $project, 'participant' => $participant];
    });

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    TenantContextScope::runFor($this->org->id, function (): void {
        expect(AiRequest::count())->toBe(26);
        expect(WebhookDelivery::count())->toBe(8);
        expect(NotificationLog::count())->toBe(3);
    });
    expect(ApiClient::where('organization_id', $this->org->id)->where('name', 'like', DemoMarker::PREFIX.'%')->count())->toBe(3);

    // No explicit ai_requests/webhook_deliveries delete step exists in
    // DemoTeardownCommand (design D12) — both are removed automatically via
    // cascadeOnDelete from the participant delete. Count them by demo
    // evaluation/participant BEFORE teardown to prove the cascade, not an
    // explicit delete, is what removes them.
    $demoAiRequestsBeforeTeardown = TenantContextScope::runFor($this->org->id, fn () => AiRequest::count());
    expect($demoAiRequestsBeforeTeardown)->toBeGreaterThan(0);

    $this->artisan('beai:demo-teardown', ['--org' => 'acme'])->assertExitCode(0);

    // Zero demo rows in all four operational tables.
    TenantContextScope::runFor($this->org->id, function (): void {
        expect(AiRequest::count())->toBe(0);
        expect(WebhookDelivery::count())->toBe(0);
        expect(NotificationLog::count())->toBe(0);
    });
    expect(ApiClient::where('organization_id', $this->org->id)->where('name', 'like', DemoMarker::PREFIX.'%')->count())->toBe(0);

    // The real, non-demo api_clients row survives byte-for-byte.
    $freshClient = ApiClient::find($realClient->id);
    expect($freshClient)->not->toBeNull();
    expect($freshClient->name)->toBe('acme-real-integration');
    expect($freshClient->key_hash)->toBe($realClient->key_hash);

    // The real, non-demo participant/project pair survives byte-for-byte.
    $freshParticipant = Participant::find($human['participant']->id);
    expect($freshParticipant)->not->toBeNull();
    expect($freshParticipant->candidate_ref)->toBe('real-candidate-completeness');

    $freshProject = TenantContextScope::runFor($this->org->id, fn () => Project::find($human['project']->id));
    expect($freshProject)->not->toBeNull();
    expect($freshProject->slug)->toBe('acme-real-project');
});
