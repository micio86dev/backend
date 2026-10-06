<?php

declare(strict_types=1);

/**
 * The read-only evaluation-retry state on the admin participant detail
 * (scoring-retry-rt-b, slice PR3a, still dark).
 *
 * `GET /api/participants/{id}` carries three flat, machine-facing fields:
 * `retry_attempt`, `retry_authorized_at` and `retry_available`. They never carry
 * anything of the pending evaluation, whose read gate is unchanged: it stays
 * unreadable until the participant is back at `completato`.
 *
 * REQ: Participant Detail Carries The Evaluation Retry State
 *      (openspec/changes/scoring-retry-rt-b/specs/admin-read-api/spec.md)
 */

use App\Enums\ApiKeyMode;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/** @return array{org: Organization, token: string, project: Project} */
function retryDetailWorld(?Organization $org = null, string $role = 'admin'): array
{
    $org ??= Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]));
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);

    return ['org' => $org, 'token' => auth('api')->login($user), 'project' => $project];
}

/** @param  string|null  $evaluation  'pending', 'completed' or null for no Evaluation row */
function retryDetailParticipant(Project $project, string $status, ?string $evaluation, bool $retryAttempt = false, ?Carbon $authorizedAt = null): Participant
{
    $participant = Participant::factory()->forProject($project)->withStatus($status)->create();

    if ($evaluation !== null) {
        $factory = Evaluation::factory();
        $factory = $evaluation === 'completed' ? $factory->completed() : $factory->pending();
        $factory->create([
            'participant_id' => $participant->id,
            'framework_version_id' => $project->framework_version_id,
            'retry_attempt' => $retryAttempt,
            'retry_authorized_at' => $authorizedAt,
        ]);
    }

    return $participant;
}

test('an eligible participant reports retry_available with no retry authorized', function (): void {
    ['token' => $token, 'project' => $project] = retryDetailWorld();
    $participant = retryDetailParticipant($project, 'completato', 'pending');

    $data = $this->withToken($token)->getJson("/api/participants/{$participant->id}")->assertOk()->json('data');

    expect($data['retry_available'])->toBeTrue()
        ->and($data['retry_attempt'])->toBeFalse()
        ->and($data['retry_authorized_at'])->toBeNull();
});

test('a participant whose evaluation completed is not retryable', function (): void {
    ['token' => $token, 'project' => $project] = retryDetailWorld();
    $participant = retryDetailParticipant($project, 'completato', 'completed');

    $data = $this->withToken($token)->getJson("/api/participants/{$participant->id}")->assertOk()->json('data');

    expect($data['retry_available'])->toBeFalse()
        ->and($data['retry_attempt'])->toBeFalse()
        ->and($data['retry_authorized_at'])->toBeNull();
});

test('an authorized retry reports its state and the literal participant status', function (): void {
    ['token' => $token, 'project' => $project] = retryDetailWorld();
    $at = Carbon::parse('2026-10-06 09:30:00');
    $participant = retryDetailParticipant($project, 'in_attesa', 'pending', true, $at);

    $data = $this->withToken($token)->getJson("/api/participants/{$participant->id}")->assertOk()->json('data');

    expect($data['retry_attempt'])->toBeTrue()
        ->and(Carbon::parse($data['retry_authorized_at'])->equalTo($at))->toBeTrue()
        ->and($data['retry_available'])->toBeFalse()
        ->and($data['status'])->toBe('in_attesa');
});

test('a completed retry is no longer available', function (): void {
    // Back at completato with the single retry consumed.
    ['token' => $token, 'project' => $project] = retryDetailWorld();
    $participant = retryDetailParticipant($project, 'completato', 'pending', true, now());

    $data = $this->withToken($token)->getJson("/api/participants/{$participant->id}")->assertOk()->json('data');

    expect($data['retry_available'])->toBeFalse()
        ->and($data['retry_attempt'])->toBeTrue();
});

test('a participant without an evaluation reports no retry state', function (): void {
    ['token' => $token, 'project' => $project] = retryDetailWorld();
    $participant = retryDetailParticipant($project, 'in_corso', null);

    $data = $this->withToken($token)->getJson("/api/participants/{$participant->id}")->assertOk()->json('data');

    expect($data['retry_attempt'])->toBeFalse()
        ->and($data['retry_authorized_at'])->toBeNull()
        ->and($data['retry_available'])->toBeFalse();
});

test('a pending evaluation is retryable only at completato', function (string $status): void {
    ['token' => $token, 'project' => $project] = retryDetailWorld();
    $participant = retryDetailParticipant($project, $status, 'pending');

    $data = $this->withToken($token)->getJson("/api/participants/{$participant->id}")->assertOk()->json('data');

    expect($data['retry_available'])->toBeFalse();
})->with(['in_attesa', 'in_corso', 'in_valutazione', 'errore']);

test('a test-mode participant is never retry_available even when its evaluation is pending', function (): void {
    // The action refuses it (`test_mode_participant`); the flag must agree.
    ['token' => $token, 'project' => $project] = retryDetailWorld();
    $participant = retryDetailParticipant($project, 'completato', 'pending');
    $participant->forceFill(['mode' => ApiKeyMode::Test])->save();

    $data = $this->withToken($token)->getJson("/api/participants/{$participant->id}")->assertOk()->json('data');

    expect($data['retry_available'])->toBeFalse();
});

test('the retry fields are machine values, never localized', function (): void {
    ['token' => $token, 'project' => $project] = retryDetailWorld();
    $participant = retryDetailParticipant($project, 'completato', 'pending');

    $it = $this->withToken($token)->withHeader('Accept-Language', 'it')->getJson("/api/participants/{$participant->id}")->json('data');
    $en = $this->withToken($token)->withHeader('Accept-Language', 'en')->getJson("/api/participants/{$participant->id}")->json('data');

    expect([$it['retry_attempt'], $it['retry_authorized_at'], $it['retry_available']])
        ->toBe([$en['retry_attempt'], $en['retry_authorized_at'], $en['retry_available']]);
});

test('the detail of another organization is 404 and exposes no retry field', function (): void {
    ['org' => $org, 'token' => $token] = retryDetailWorld();
    $other = Organization::factory()->create();
    $participant = TenantContextScope::runFor($other->id, function () use ($other): Participant {
        $fv = FrameworkVersion::factory()->create(['organization_id' => $other->id]);
        $project = Project::factory()->create(['framework_version_id' => $fv->id]);

        return retryDetailParticipant($project, 'completato', 'pending');
    });
    app(TenantResolver::class)->setOrgId($org->id);

    $response = $this->withToken($token)->getJson("/api/participants/{$participant->id}");

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('retry_');
});

test('the structured evaluation stays unreadable while a retry is in flight', function (string $status): void {
    ['token' => $token, 'project' => $project] = retryDetailWorld();
    $participant = retryDetailParticipant($project, $status, 'pending', true, now());

    $this->withToken($token)->getJson("/api/participants/{$participant->id}/evaluation")->assertStatus(409);
})->with(['in_attesa', 'in_corso', 'in_valutazione']);
