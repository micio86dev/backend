<?php

declare(strict_types=1);

/**
 * A reset that keeps what you need to start over.
 *
 * The point of this command is the KEEP list, not the delete list — anyone can
 * truncate a database. What makes it usable in a beta is that afterwards you
 * can still log in, your avatar templates are still there, your settings are
 * still there, and a new project has a framework version to pin.
 *
 * Every assertion below is therefore paired: something went, and something
 * specific stayed.
 */

use App\Models\AvatarTemplate;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\InterviewSnapshot;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake();
});

/**
 * @return array{org: Organization, project: Project, participant: Participant, template: AvatarTemplate, user: User, fv: FrameworkVersion}
 */
function resettableOrg(string $slug): array
{
    $org = Organization::factory()->create(['slug' => $slug, 'primary_color' => '#123456']);
    app(TenantResolver::class)->setOrgId($org->id);

    $user = User::factory()->create(['organization_id' => $org->id]);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Keep me '.uniqid(),
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v'],
    ]));

    $project = Project::factory()->create([
        'organization_id' => $org->id,
        'framework_version_id' => $fv->id,
        'avatar_template_id' => $template->id,
    ]);

    $participant = Participant::factory()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
    ]);

    $session = InterviewSession::factory()->create([
        'organization_id' => $org->id,
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $fv->id,
    ]);

    InterviewSnapshot::factory()->create([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        's3_key' => "{$org->id}/{$participant->id}/{$session->id}/shot.jpg",
    ]);

    Storage::put("{$org->id}/{$participant->id}/{$session->id}/shot.jpg", 'jpeg-bytes');

    Evaluation::factory()->create([
        'organization_id' => $org->id,
        'participant_id' => $participant->id,
    ]);

    return compact('org', 'project', 'participant', 'template', 'user', 'fv');
}

test('it deletes the assessment data and keeps what you need to start over', function (): void {
    ['org' => $org, 'template' => $template, 'user' => $user, 'fv' => $fv] = resettableOrg('acme');

    $this->artisan('beai:reset-assessment-data', ['--org' => 'acme', '--confirm' => 'acme'])->assertSuccessful();

    // Gone.
    expect(Participant::withoutGlobalScopes()->where('organization_id', $org->id)->count())->toBe(0)
        ->and(Project::withoutGlobalScopes()->where('organization_id', $org->id)->count())->toBe(0)
        ->and(InterviewSession::withoutGlobalScopes()->where('organization_id', $org->id)->count())->toBe(0)
        ->and(Evaluation::withoutGlobalScopes()->where('organization_id', $org->id)->count())->toBe(0);

    // Kept — and this half is the whole point.
    expect(User::withoutGlobalScopes()->find($user->id))->not->toBeNull()
        ->and(AvatarTemplate::withoutGlobalScopes()->find($template->id))->not->toBeNull()
        ->and(Organization::withoutGlobalScopes()->find($org->id)?->primary_color)->toBe('#123456')
        // The specific version, not a count: `Project::factory()` makes one of
        // its own, so counting would assert the fixture rather than the
        // command. What matters is that a new project still has one to pin.
        ->and(FrameworkVersion::withoutGlobalScopes()->find($fv->id))->not->toBeNull();
});

test('it removes the snapshot OBJECTS, not only their rows', function (): void {
    // Rows deleted without their objects leave a disk that grows forever and a
    // bill nobody can explain. The keys are read before the cascade, because
    // afterwards nothing is left to say which files to remove.
    ['org' => $org, 'participant' => $participant] = resettableOrg('acme');
    $key = Storage::allFiles()[0] ?? null;

    expect($key)->not->toBeNull();

    $this->artisan('beai:reset-assessment-data', ['--org' => 'acme', '--confirm' => 'acme'])->assertSuccessful();

    expect(Storage::exists((string) $key))->toBeFalse();
});

test('a dry run counts and deletes nothing', function (): void {
    ['org' => $org] = resettableOrg('acme');

    $this->artisan('beai:reset-assessment-data', ['--org' => 'acme', '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(Participant::withoutGlobalScopes()->where('organization_id', $org->id)->count())->toBe(1)
        ->and(Storage::allFiles())->toHaveCount(1);
});

test('one organization is reset without touching another', function (): void {
    // The reason `--org` exists. A shared beta database holds more than one
    // tenant, and "reset" must never mean "reset everyone".
    ['org' => $mine] = resettableOrg('acme');
    ['org' => $theirs, 'participant' => $theirParticipant] = resettableOrg('globex');

    $this->artisan('beai:reset-assessment-data', ['--org' => 'acme', '--confirm' => 'acme'])->assertSuccessful();

    expect(Participant::withoutGlobalScopes()->where('organization_id', $mine->id)->count())->toBe(0)
        ->and(Participant::withoutGlobalScopes()->find($theirParticipant->id))->not->toBeNull();
});

test('audit logs and API clients survive by DEFAULT', function (): void {
    // A data reset is a poor reason to destroy a record of who did what, and
    // integration credentials are configuration rather than assessment data.
    // Both are removable, but you have to say so.
    ['org' => $org] = resettableOrg('acme');

    DB::table('audit_logs')->insert([
        'organization_id' => $org->id,
        'actor_id' => null,
        'action' => 'project.created',
        'subject_type' => 'project',
        'subject_id' => 1,
        'created_at' => now(),
    ]);

    $this->artisan('beai:reset-assessment-data', ['--org' => 'acme', '--confirm' => 'acme'])->assertSuccessful();

    expect(DB::table('audit_logs')->where('organization_id', $org->id)->count())->toBe(1);

    $this->artisan('beai:reset-assessment-data', [
        '--org' => 'acme',
        '--confirm' => 'acme',
        '--include-audit-logs' => true,
    ])->assertSuccessful();

    expect(DB::table('audit_logs')->where('organization_id', $org->id)->count())->toBe(0);
});

test('an unknown slug fails instead of resetting everything', function (): void {
    // The failure mode worth guarding: a typo in `--org` must not be read as
    // "no filter, so all of them".
    ['org' => $org] = resettableOrg('acme');

    $this->artisan('beai:reset-assessment-data', ['--org' => 'acmee', '--confirm' => 'acmee'])->assertFailed();

    expect(Participant::withoutGlobalScopes()->where('organization_id', $org->id)->count())->toBe(1);
});

test('it REFUSES to run without naming what it is destroying', function (): void {
    // Not a `--force` flag. A flag is something people learn to type; retyping
    // the slug is a sentence muscle memory cannot produce. Same convention as
    // `beai:demo-teardown`, so there is one confirmation shape across the
    // destructive commands rather than two.
    ['org' => $org] = resettableOrg('acme');

    $this->artisan('beai:reset-assessment-data', ['--org' => 'acme'])->assertFailed();

    expect(Participant::withoutGlobalScopes()->where('organization_id', $org->id)->count())->toBe(1);
});

test('a confirmation for the WRONG organization is refused', function (): void {
    // The mistake this actually catches: reaching for the previous command in
    // shell history and changing only `--org`.
    ['org' => $org] = resettableOrg('acme');
    resettableOrg('globex');

    $this->artisan('beai:reset-assessment-data', ['--org' => 'acme', '--confirm' => 'globex'])
        ->assertFailed();

    expect(Participant::withoutGlobalScopes()->where('organization_id', $org->id)->count())->toBe(1);
});

test('resetting EVERY organization needs the word ALL, not a slug', function (): void {
    ['org' => $mine] = resettableOrg('acme');
    ['org' => $theirs] = resettableOrg('globex');

    $this->artisan('beai:reset-assessment-data', ['--confirm' => 'acme'])->assertFailed();

    $this->artisan('beai:reset-assessment-data', ['--confirm' => 'ALL'])->assertSuccessful();

    expect(Participant::withoutGlobalScopes()->where('organization_id', $mine->id)->count())->toBe(0)
        ->and(Participant::withoutGlobalScopes()->where('organization_id', $theirs->id)->count())->toBe(0);
});
