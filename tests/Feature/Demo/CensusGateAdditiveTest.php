<?php

declare(strict_types=1);

/**
 * The production-safety proof (design D11; non-negotiable #3 of this
 * change): a live, already-seeded production dataset predates the four
 * operational tables. Re-running `beai:demo-seed` against it must TOP UP the
 * missing surfaces, never refuse and never demand `beai:demo-teardown`.
 *
 * Deleting every row in the four new tables after a full seed simulates
 * exactly that older dataset: the four MARKED ROOTS (`projects`,
 * `participants`, `framework_versions`, `avatar_templates`) are still
 * complete, so `checkCensusGate`'s shallow census still matches exactly and
 * lets the run through — the four new writers then complete the missing
 * surfaces because none of them checks anything beyond "does a row already
 * exist for this parent".
 */

use App\Models\AiRequest;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\FrameworkVersion;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Support\Demo\DemoMarker;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

test('deleting all four operational tables and re-seeding tops them up with exit 0 and no PARTIAL, leaving the four marked roots unchanged', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $projectsBefore = Project::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('slug', 'like', DemoMarker::PREFIX.'%')->count();
    $participantsBefore = Participant::where('organization_id', $this->org->id)->where('candidate_ref', 'like', DemoMarker::PREFIX.'%')->count();
    $versionsBefore = FrameworkVersion::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('version', 'like', DemoMarker::PREFIX.'%')->count();
    $templatesBefore = AvatarTemplate::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('name', 'like', DemoMarker::PREFIX.'%')->count();

    // Simulate the OLDER, already-shipped dataset: delete every row in the
    // four new tables (never the four marked roots).
    TenantContextScope::runFor($this->org->id, function (): void {
        AiRequest::query()->delete();
        WebhookDelivery::query()->delete();
        NotificationLog::query()->delete();
    });
    ApiClient::where('organization_id', $this->org->id)->where('name', 'like', DemoMarker::PREFIX.'%')->delete();

    TenantContextScope::runFor($this->org->id, function (): void {
        expect(AiRequest::count())->toBe(0);
        expect(WebhookDelivery::count())->toBe(0);
        expect(NotificationLog::count())->toBe(0);
    });
    expect(ApiClient::where('organization_id', $this->org->id)->where('name', 'like', DemoMarker::PREFIX.'%')->count())->toBe(0);

    // Artisan::call() runs synchronously, so the DB assertions below see the
    // finished run. It also returns the real captured buffer: PendingCommand's
    // doesntExpectOutputToContain() cannot see text written through error(), and
    // the refusal this test guards against ("PARTIAL demo dataset") is an error().
    $exit = Artisan::call('beai:demo-seed', ['--org' => 'acme']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->not->toContain('PARTIAL');

    // The four marked roots are byte-for-byte unchanged — nothing was
    // re-created, nothing was duplicated.
    expect(Project::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('slug', 'like', DemoMarker::PREFIX.'%')->count())->toBe($projectsBefore);
    expect(Participant::where('organization_id', $this->org->id)->where('candidate_ref', 'like', DemoMarker::PREFIX.'%')->count())->toBe($participantsBefore);
    expect(FrameworkVersion::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('version', 'like', DemoMarker::PREFIX.'%')->count())->toBe($versionsBefore);
    expect(AvatarTemplate::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('name', 'like', DemoMarker::PREFIX.'%')->count())->toBe($templatesBefore);

    // The four operational surfaces are topped back up.
    TenantContextScope::runFor($this->org->id, function (): void {
        expect(AiRequest::count())->toBe(26);
        expect(WebhookDelivery::count())->toBe(8);
        expect(NotificationLog::count())->toBe(3);
    });
    expect(ApiClient::where('organization_id', $this->org->id)->where('name', 'like', DemoMarker::PREFIX.'%')->count())->toBe(3);
});

test('a genuinely partial marked-root dataset still refuses after the operational surfaces exist', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $participant = Participant::where('organization_id', $this->org->id)
        ->where('candidate_ref', DemoMarker::PREFIX.'c-006')
        ->firstOrFail();
    $participant->delete();

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])
        ->expectsOutputToContain('artial')
        ->assertExitCode(1);
});
