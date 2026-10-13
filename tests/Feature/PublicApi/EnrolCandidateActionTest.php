<?php

declare(strict_types=1);

/**
 * `EnrolCandidate` is the one place a public-API candidate becomes a participant.
 * What matters here is what it refuses, and that a refusal or a failure part-way
 * leaves nothing behind: no participant, no events, no half-minted token.
 */

use App\Actions\PublicApi\EnrolCandidate;
use App\Enums\ApiKeyMode;
use App\Exceptions\PublicApi\EnrolmentRefusalReason;
use App\Exceptions\PublicApi\EnrolmentRefused;
use App\Models\InterviewEvent;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: Project}
 */
function enrolFixture(array $organizationAttributes = [], array $projectAttributes = []): array
{
    $org = Organization::factory()->create($organizationAttributes);
    $project = TenantContextScope::runFor($org->id, fn () => Project::factory()->create(
        $projectAttributes + ['organization_id' => $org->id, 'status' => 'active'],
    ));

    return [$org, $project];
}

/**
 * @return array{candidate_ref: string, display_name: string, email: string, language: string|null}
 */
function enrolCandidate(string $ref = 'cand-1', string $email = 'Ana@Example.test'): array
{
    return ['candidate_ref' => $ref, 'display_name' => 'Ana', 'email' => $email, 'language' => null];
}

function enrol(Project $project, Organization $org, array $candidate, ?string $exitUrl = null): void
{
    TenantContextScope::runFor($org->id, fn () => app(EnrolCandidate::class)->handle($project, $org, $candidate, null, $exitUrl, ApiKeyMode::Live));
}

function refusalOf(Closure $call): ?EnrolmentRefusalReason
{
    try {
        $call();
    } catch (EnrolmentRefused $e) {
        return $e->reason;
    }

    return null;
}

test('enrolling creates a pending participant with a lower-cased email, both lifecycle events and a stamped token id', function (): void {
    [$org, $project] = enrolFixture();

    $result = TenantContextScope::runFor($org->id, fn () => app(EnrolCandidate::class)->handle($project, $org, enrolCandidate(), null, null, ApiKeyMode::Live));

    $participant = $result->participant->fresh();
    expect($participant->email)->toBe('ana@example.test')
        ->and($participant->organization_id)->toBe($org->id)
        ->and($participant->session_token_jti)->toBe($result->sessionToken->jti)
        ->and(TenantContextScope::runFor($org->id, fn () => InterviewEvent::where('participant_id', $participant->id)->pluck('type')->sort()->values()->all()))->toBe(['created', 'invited']);
});

test('a project that is not accessible is refused before anything is written', function (): void {
    [$org, $project] = enrolFixture(projectAttributes: ['status' => 'draft']);

    expect(refusalOf(fn () => enrol($project, $org, enrolCandidate())))->toBe(EnrolmentRefusalReason::ProjectNotActive)
        ->and(Participant::where('project_id', $project->id)->exists())->toBeFalse();
});

test('the same email in another case, or the same candidate ref, is a duplicate with the right reason', function (): void {
    [$org, $project] = enrolFixture();
    enrol($project, $org, enrolCandidate('cand-1', 'ana@example.test'));

    expect(refusalOf(fn () => enrol($project, $org, enrolCandidate('cand-2', 'ANA@example.test'))))->toBe(EnrolmentRefusalReason::DuplicateEmail)
        ->and(refusalOf(fn () => enrol($project, $org, enrolCandidate('cand-1', 'other@example.test'))))->toBe(EnrolmentRefusalReason::DuplicateCandidateRef)
        ->and(Participant::where('project_id', $project->id)->count())->toBe(1);
});

test('an exit redirect is accepted only on an allowed host, compared case-insensitively, and a hostless URL is refused', function (): void {
    [$org, $project] = enrolFixture(['allowed_domains' => ['hr.acme.example']]);

    expect(refusalOf(fn () => enrol($project, $org, enrolCandidate('c1', 'a@x.test'), 'https://HR.Acme.example/done')))->toBeNull()
        ->and(refusalOf(fn () => enrol($project, $org, enrolCandidate('c2', 'b@x.test'), 'https://evil.example/done')))->toBe(EnrolmentRefusalReason::RedirectUrlNotAllowed)
        ->and(refusalOf(fn () => enrol($project, $org, enrolCandidate('c3', 'c@x.test'), 'not-a-url')))->toBe(EnrolmentRefusalReason::RedirectUrlNotAllowed)
        ->and(Participant::where('project_id', $project->id)->count())->toBe(1);
});

test('losing the uniqueness race at the database maps to the same duplicate reasons as the pre-check', function (string $competingEmail, string $competingRef, EnrolmentRefusalReason $expected): void {
    [$org, $project] = enrolFixture();
    $armed = true;
    DB::beforeExecuting(function (string $query) use (&$armed, $project, $competingEmail, $competingRef): void {
        if ($armed && str_starts_with($query, 'insert into "participants"')) {
            $armed = false;
            // The concurrent winner, committed between the pre-check and our insert.
            Participant::factory()->forProject($project)->create(['candidate_ref' => $competingRef, 'email' => $competingEmail]);
        }
    });

    expect(refusalOf(fn () => enrol($project, $org, enrolCandidate('loser-ref', 'loser@example.test'))))->toBe($expected);
})->with([
    'same email' => ['loser@example.test', 'winner-ref', EnrolmentRefusalReason::DuplicateEmail],
    'same candidate ref' => ['winner@example.test', 'loser-ref', EnrolmentRefusalReason::DuplicateCandidateRef],
]);

test('a database error that is not one of the two uniqueness constraints is not disguised as a refusal', function (): void {
    [$org, $project] = enrolFixture();
    $armed = true;
    DB::beforeExecuting(function (string $query) use (&$armed): void {
        if ($armed && str_starts_with($query, 'insert into "participants"')) {
            $armed = false;
            throw new QueryException('pgsql', $query, [], new RuntimeException('connection reset'));
        }
    });

    expect(fn () => enrol($project, $org, enrolCandidate()))->toThrow(QueryException::class);
    expect(Participant::where('project_id', $project->id)->exists())->toBeFalse();
});

test('a token that cannot be minted rolls the whole enrolment back, so a retry is not met with a phantom duplicate', function (): void {
    [$org, $project] = enrolFixture();
    config(['public_api.session_secret' => '']);

    expect(fn () => enrol($project, $org, enrolCandidate()))->toThrow(RuntimeException::class, 'session_secret');

    expect(Participant::where('project_id', $project->id)->exists())->toBeFalse()
        ->and(TenantContextScope::runFor($org->id, fn () => InterviewEvent::count()))->toBe(0);

    config(['public_api.session_secret' => 'a-restored-secret-of-sufficient-length-0123456789']);
    enrol($project, $org, enrolCandidate());

    expect(Participant::where('project_id', $project->id)->count())->toBe(1);
});
