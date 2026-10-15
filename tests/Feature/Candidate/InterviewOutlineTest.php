<?php

declare(strict_types=1);

/**
 * GET /api/candidate/interview/outline (live-interview-call-chrome, T-A2.1).
 *
 * A read-only, owned, localized view of the candidate's interview path. Every
 * test is a scenario of the "Interview outline" requirement of
 * `specs/interview-session/spec.md`, driven through the REAL endpoint with a
 * real candidate JWT.
 *
 * Competency statuses are derived from `CompetencyProgress`, the same rule that
 * picks the competency the next `POST /start` serves, so "current" is asserted
 * here against rows written the way `/start` and the evaluation retry write them.
 *
 * REQ: interview-session "GET /candidate/interview/outline"
 */

use App\Models\AvatarTemplate;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const OLN_URL = '/api/candidate/interview/outline';

/**
 * A standard project (English, ICO) with `$count` competencies and one participant.
 *
 * `casProject()` gives every competency exactly one primary question; the
 * competencies are named so a status or a name can be told apart by eye.
 *
 * @return array{0: Organization, 1: Project, 2: list<Competency>, 3: Participant}
 */
function olnWorld(int $count = 3, string $language = 'en'): array
{
    $org = casOrg();
    [$project, $competencies] = casProject($org, $count);

    $project->update(['language' => $language]);

    foreach ($competencies as $i => $competency) {
        $competency->setTranslations('name', ['en' => 'Skill '.($i + 1), 'it' => 'Competenza '.($i + 1)])->save();
    }

    $participant = casParticipant($org, $project, 'in_corso');

    return [$org, $project, $competencies, $participant];
}

function olnGet(Participant $participant, array $headers = [], string $query = ''): TestResponse
{
    // The guard keeps the first request's user for the life of the app: a second candidate in the same test must start clean.
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)] + $headers)
        ->getJson(OLN_URL.$query);
}

function olnSession(Participant $participant, Project $project, Competency $competency, string $status, int $errorCount = 0, ?array $primaries = null): InterviewSession
{
    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $competency->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'provider_session_ref' => 'ref-'.uniqid(),
        'status' => $status,
        'primary_questions' => $primaries,
    ]);

    DB::table('interview_sessions')->where('id', $session->id)->update(['error_count' => $errorCount]);

    return $session;
}

function olnAddPrimaries(Project $project, Competency $competency, int $extra): void
{
    for ($i = 0; $i < $extra; $i++) {
        ProjectQuestion::create([
            'project_id' => $project->id,
            'competency_id' => $competency->id,
            'text' => ['en' => "Extra question {$i}", 'it' => "Domanda extra {$i}"],
            'position' => $i + 1,
        ]);
    }
}

function olnTemplate(?string $displayName): AvatarTemplate
{
    return AvatarTemplate::create([
        'name' => 'Outline template '.uniqid(),
        'provider' => 'heygen',
        'config' => [],
        'persona' => $displayName === null ? null : ['display_name' => $displayName],
    ]);
}

/** @return list<string> */
function olnStatuses(TestResponse $response): array
{
    return array_column($response->json('competencies'), 'status');
}

// ─── Shape, names and the persona ─────────────────────────────────────────────

test('a standard interview returns role, avatar, candidate and ordered competencies and nothing else', function (): void {
    [, $project, $competencies, $participant] = olnWorld(3, 'it');
    Role::where('code', $project->role_code)->firstOrFail()
        ->setTranslations('name', ['en' => 'Sales Lead', 'it' => 'Responsabile Vendite'])->save();
    olnAddPrimaries($project, $competencies[0], 1);
    olnAddPrimaries($project, $competencies[1], 2);
    $project->update(['avatar_template_id' => olnTemplate('Giulia')->id]);
    DB::table('participants')->where('id', $participant->id)->update(['display_name' => '  Marco Rossi  ']);

    $response = olnGet($participant)->assertOk();

    expect(array_keys($response->json()))->toEqualCanonicalizing(
        ['assessment_type', 'role_name', 'avatar_name', 'candidate_name', 'competencies', 'primary_total_all'],
    );
    $response->assertJsonPath('assessment_type', 'standard')
        ->assertJsonPath('role_name', 'Responsabile Vendite')
        ->assertJsonPath('avatar_name', 'Giulia')
        ->assertJsonPath('candidate_name', 'Marco Rossi')
        ->assertJsonPath('primary_total_all', 6);
    expect($response->json('competencies'))->toBe([
        ['name' => 'Competenza 1', 'ordinal' => 1, 'status' => 'current', 'primary_total' => 2],
        ['name' => 'Competenza 2', 'ordinal' => 2, 'status' => 'upcoming', 'primary_total' => 3],
        ['name' => 'Competenza 3', 'ordinal' => 3, 'status' => 'upcoming', 'primary_total' => 1],
    ]);
    foreach ($competencies as $competency) {
        expect($response->getContent())->not->toContain($competency->code);
    }
});

test('a potential assessment has no role name and lists the potential competencies', function (): void {
    $org = casOrg();
    casProject($org, 1); // seeds the tenant context and the catalogue role
    $project = Project::factory()->potential()->create(['status' => 'active']);
    $competency = Competency::factory()->potential()->create(['name' => ['en' => 'Mastery']]);
    DB::table('project_competencies')->insert(['project_id' => $project->id, 'competency_id' => $competency->id, 'position' => 0]);
    ProjectQuestion::create(['project_id' => $project->id, 'competency_id' => $competency->id, 'text' => ['en' => 'Q'], 'position' => 0]);
    $participant = casParticipant($org, $project, 'in_corso');

    $response = olnGet($participant)->assertOk();

    $response->assertJsonPath('assessment_type', 'potential')->assertJsonPath('role_name', null);
    expect($response->json('competencies.0.name'))->toBe('Mastery');
});

test('an unset persona name, an invalid one and a blank participant name all return null', function (string $stored): void {
    [, $project, , $participant] = olnWorld(1);
    $project->update(['avatar_template_id' => olnTemplate($stored === 'unset' ? null : ($stored === 'long' ? str_repeat('a', 61) : 'Gi<b>ulia'))->id]);
    DB::table('participants')->where('id', $participant->id)->update(['display_name' => '   ']);

    olnGet($participant)->assertOk()
        ->assertJsonPath('avatar_name', null)
        ->assertJsonPath('candidate_name', null);
})->with(['unset', 'long', 'angle bracket']);

test('a 60 character persona name is returned unchanged', function (): void {
    [, $project, , $participant] = olnWorld(1);
    $name = str_repeat('a', 60);
    $project->update(['avatar_template_id' => olnTemplate($name)->id]);

    olnGet($participant)->assertOk()->assertJsonPath('avatar_name', $name);
});

// ─── Status ───────────────────────────────────────────────────────────────────

test('status follows the interview state', function (): void {
    [, $project, $competencies, $participant] = olnWorld(3);
    olnSession($participant, $project, $competencies[0], 'completed');
    olnSession($participant, $project, $competencies[1], 'in_corso');

    expect(olnStatuses(olnGet($participant)->assertOk()))->toBe(['done', 'current', 'upcoming']);
});

test('an errored competency with attempts left that is next reports current', function (): void {
    [, $project, $competencies, $participant] = olnWorld(3);
    olnSession($participant, $project, $competencies[0], 'completed');
    olnSession($participant, $project, $competencies[1], 'error', errorCount: 1);

    expect(olnStatuses(olnGet($participant)->assertOk()))->toBe(['done', 'current', 'upcoming']);
});

test('a later competency queued behind the current one reports retry', function (): void {
    [, $project, $competencies, $participant] = olnWorld(4);
    olnSession($participant, $project, $competencies[0], 'completed');
    olnSession($participant, $project, $competencies[1], 'pending');
    olnSession($participant, $project, $competencies[2], 'completed');
    olnSession($participant, $project, $competencies[3], 'pending');

    expect(olnStatuses(olnGet($participant)->assertOk()))->toBe(['done', 'current', 'done', 'retry']);
});

test('exhausted re-offer attempts close the competency', function (): void {
    [, $project, $competencies, $participant] = olnWorld(3);
    olnSession($participant, $project, $competencies[0], 'completed');
    olnSession($participant, $project, $competencies[1], 'error', errorCount: InterviewSession::MAX_ERROR_ATTEMPTS);

    expect(olnStatuses(olnGet($participant)->assertOk()))->toBe(['done', 'done', 'current']);
});

test('timeout and skipped sessions are done', function (): void {
    [, $project, $competencies, $participant] = olnWorld(3);
    olnSession($participant, $project, $competencies[0], 'timeout');
    olnSession($participant, $project, $competencies[1], 'skipped');

    expect(olnStatuses(olnGet($participant)->assertOk()))->toBe(['done', 'done', 'current']);
});

test('a participant still in progress with every competency closed has no current competency', function (): void {
    [, $project, $competencies, $participant] = olnWorld(2);
    olnSession($participant, $project, $competencies[0], 'completed');
    olnSession($participant, $project, $competencies[1], 'completed');

    expect(olnStatuses(olnGet($participant)->assertOk()))->toBe(['done', 'done']);
});

// ─── Refusals ─────────────────────────────────────────────────────────────────

test('a terminal participant is refused by the participant status guard', function (string $status): void {
    [$org, $project] = olnWorld(1);
    $terminal = casParticipant($org, $project, $status);

    olnGet($terminal)->assertForbidden()->assertJsonMissingPath('competencies');
})->with(['completato', 'errore']);

test('an unsupported assessment type is refused like /start', function (): void {
    [, $project, , $participant] = olnWorld(1);
    DB::table('projects')->where('id', $project->id)->update(['assessment_type' => 'exotic']);

    olnGet($participant)->assertStatus(422)->assertJsonPath('error', 'assessment_type_not_supported');
});

test('a participant whose project was deleted is refused like /start', function (): void {
    [, $project, , $participant] = olnWorld(1);
    $project->delete();

    olnGet($participant)->assertStatus(422)->assertJsonPath('error', 'project_not_found');
});

test('no token and an expired token are refused with the status /candidate/session gives', function (): void {
    [, , , $participant] = olnWorld(1);

    $whoamiAnonymous = $this->getJson('/api/candidate/session')->getStatusCode();
    $this->getJson(OLN_URL)->assertStatus($whoamiAnonymous);

    $token = casBearer($participant);
    Carbon::setTestNow(Carbon::now()->addHours(3));
    $whoamiExpired = $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/candidate/session')->getStatusCode();
    $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson(OLN_URL)->assertStatus($whoamiExpired);

    expect([$whoamiAnonymous, $whoamiExpired])->toBe([401, 401]);
});

// ─── primary_total ────────────────────────────────────────────────────────────

test('a frozen session snapshot beats the live primary count', function (): void {
    [, $project, $competencies, $participant] = olnWorld(2);
    olnAddPrimaries($project, $competencies[0], 2); // live count for competency 1 is now 3
    olnSession($participant, $project, $competencies[0], 'in_corso', primaries: ['Frozen one', 'Frozen two']);

    $response = olnGet($participant)->assertOk();

    expect(array_column($response->json('competencies'), 'primary_total'))->toBe([2, 1]);
    $response->assertJsonPath('primary_total_all', 3);
});

test('a competency without a primary question reports zero', function (): void {
    [, $project, $competencies, $participant] = olnWorld(3);
    ProjectQuestion::where('project_id', $project->id)->where('competency_id', $competencies[1]->id)->delete();

    $response = olnGet($participant)->assertOk();

    expect(array_column($response->json('competencies'), 'primary_total'))->toBe([1, 0, 1]);
    $response->assertJsonPath('primary_total_all', 2);
});

// ─── Names ────────────────────────────────────────────────────────────────────

test('names follow the project language, not the request language', function (): void {
    [, $project, , $participant] = olnWorld(2, 'en');
    Role::where('code', $project->role_code)->firstOrFail()
        ->setTranslations('name', ['en' => 'Sales Lead', 'it' => 'Responsabile Vendite'])->save();

    $response = olnGet($participant, ['Accept-Language' => 'it'])->assertOk();

    $response->assertJsonPath('role_name', 'Sales Lead');
    expect(array_column($response->json('competencies'), 'name'))->toBe(['Skill 1', 'Skill 2']);
});

// A name blank in EVERY language cannot be stored (`framework_*_name_nonblank_check` requires a non-blank `en`),
// so the catalogue default is the last fallback a real row can reach.
test('a blank project-language name falls back to the catalogue default and never to the code', function (): void {
    [, , $competencies, $participant] = olnWorld(3, 'it');
    $competencies[0]->setTranslations('name', ['en' => 'English only', 'it' => ''])->save();

    $response = olnGet($participant)->assertOk();

    expect(array_column($response->json('competencies'), 'name'))->toBe(['English only', 'Competenza 2', 'Competenza 3']);
    foreach ($competencies as $competency) {
        expect($response->getContent())->not->toContain($competency->code);
    }
});

// ─── Tenant scope, identifiers and mutations ─────────────────────────────────

test("another tenant's data is never returned", function (): void {
    [, $projectA, $competenciesA, $participantA] = olnWorld(2);
    olnAddPrimaries($projectA, $competenciesA[0], 1); // org A: 2 primaries on competency 1
    $projectA->update(['avatar_template_id' => olnTemplate('Alpha')->id]);

    $orgB = casOrg();
    [$projectB] = casProject($orgB, 1);
    DB::table('project_competencies')->where('project_id', $projectB->id)->delete();
    foreach ($competenciesA as $position => $competency) {
        DB::table('project_competencies')->insert(['project_id' => $projectB->id, 'competency_id' => $competency->id, 'position' => $position]);
    }
    olnAddPrimaries($projectB, $competenciesA[0], 4); // org B: 4 primaries on the SAME competency
    $projectB->update(['avatar_template_id' => olnTemplate('Beta')->id]);
    $participantB = casParticipant($orgB, $projectB, 'in_corso');
    olnSession($participantB, $projectB, $competenciesA[0], 'completed');

    $response = olnGet($participantA)->assertOk();

    $response->assertJsonPath('avatar_name', 'Alpha');
    expect($response->json('competencies.0.primary_total'))->toBe(2)
        ->and(olnStatuses($response))->toBe(['current', 'upcoming']);
    expect(olnGet($participantB)->assertOk()->json('avatar_name'))->toBe('Beta');
});

test('the endpoint accepts no identifiers and mutates nothing', function (): void {
    [$org, $project, $competencies, $participant] = olnWorld(2);
    olnSession($participant, $project, $competencies[0], 'in_corso');
    $other = casParticipant($org, $project, 'in_corso');
    $foreign = olnSession($other, $project, $competencies[1], 'completed');

    $baseline = olnGet($participant)->assertOk()->json();
    $tables = fn () => [
        DB::table('interview_sessions')->count(),
        DB::table('utterances')->count(),
        Participant::withoutGlobalScopes()->whereKey($participant->id)->value('status'),
    ];
    $before = $tables();

    $withForeign = olnGet($participant, query: "?participant_id={$other->id}&session_id={$foreign->id}&project_id=999")->assertOk()->json();

    expect($withForeign)->toBe($baseline)
        ->and($tables())->toBe($before);
});

test('the outline is read-only: a repeated call changes no row', function (): void {
    [, $project, $competencies, $participant] = olnWorld(2);
    olnSession($participant, $project, $competencies[0], 'in_corso');
    $snapshot = fn () => DB::table('interview_sessions')->orderBy('id')->get()->toJson();
    $before = $snapshot();

    olnGet($participant)->assertOk();
    olnGet($participant)->assertOk();

    expect($snapshot())->toBe($before);
});
