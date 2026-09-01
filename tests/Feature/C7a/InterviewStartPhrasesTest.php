<?php

declare(strict_types=1);

/**
 * InterviewController::start() localized completion-phrase tests
 * (C7a follow-up — interview-frontend addendum).
 *
 * Tests POST /api/candidate/interview/start question_context localization.
 *
 * Asserts (per interview-frontend delta spec — ADDED Requirement:
 * "POST /start question_context — localized completion phrases"):
 * - question_context.end_phrase + final_phrase are present and non-empty in the
 *   PROJECT language (it).
 *
 * SOURCE CHANGED (avatar-language-follows-project, D4): these resolved from
 * `participant.language` until the phrases were brought into line with
 * `interview-session/spec.md`, which already required the project language. The
 * participant value arrives from unvalidated M2M input — a caller can post 'fr'
 * on an 'it' project — so it could disagree with everything else the avatar was
 * given.
 * - Same fields are present and non-empty English strings for language 'en',
 *   and DIFFER from the Italian strings.
 * - Fallback: a participant whose language has no phrase file (e.g. 'fr') falls
 *   back to the platform default language (config app.fallback_locale = en) —
 *   the fields are still present and non-null (an absent field is a contract violation).
 *
 * REQ: interview-frontend addendum — localized question_context phrases
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function phrasesOrg(): Organization
{
    return Organization::factory()->create();
}

function phrasesProjectWithCompetencies(Organization $org, int $count = 1, ?string $language = 'en'): array
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active', 'language' => $language]);

    // C8 (M-3): seed a Role matching project.role_code so SystemPromptComposer can succeed.
    $role = Role::factory()->create(['code' => $project->role_code]);

    $competencies = [];
    for ($i = 0; $i < $count; $i++) {
        $comp = Competency::factory()->create();
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $comp->id,
            'position' => $i + 1,
        ]);

        // Seed a minimal BarsIndicator (EN) so composition succeeds for EN projects.
        $ind = new BarsIndicator;
        $ind->forceFill([
            'role_id' => $role->id,
            'competency_id' => $comp->id,
            // Seeded in every locale these tests use. The phrases now resolve from
            // the PROJECT language, so a project set to `it` or `fr` must still
            // compose — otherwise /start fails at composition and the test reads
            // as a phrase bug when it is a fixture gap.
            'text' => ['en' => "Phrases fixture indicator {$i}", 'it' => "Indicatore {$i}", 'fr' => "Indicateur {$i}"],
            'anchor_5' => ['en' => "Excellent {$i}", 'it' => "Eccellente {$i}", 'fr' => "Excellent {$i}"],
            'anchor_3' => ['en' => "Adequate {$i}", 'it' => "Adeguato {$i}", 'fr' => "Adequat {$i}"],
            'anchor_1' => ['en' => "Insufficient {$i}", 'it' => "Insufficiente {$i}", 'fr' => "Insuffisant {$i}"],
            'position' => 0,
        ]);
        $ind->save();

        $competencies[] = $comp;
    }

    return [$project, $competencies];
}

function phrasesParticipant(Organization $org, Project $project, ?string $language): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'phrases-'.uniqid(),
        'display_name' => 'Phrases Test Candidate',
        'email' => uniqid('cand-').'@example.test',
        'language' => $language,
        'status' => 'in_attesa',
    ]);
    $p->save();

    return $p->fresh();
}

function phrasesHeygenSuccess(): array
{
    return [
        '*liveavatar*/contexts*' => Http::response(['data' => ['context_id' => 'ctx-phr']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => [
                'session_id' => 'heygen-session-'.uniqid(),
                'session_token' => 'heygen-token-'.uniqid(),
                'url' => 'https://webrtc.heygen.com/test',
            ],
        ], 200),
        '*liveavatar*/sessions/*' => Http::response([], 200),
    ];
}

function phrasesStart(Participant $participant): TestResponse
{
    $token = CandidateTokenFactory::mintCandidateToken($participant);

    return test()
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('POST /start question_context contains non-empty Italian end_phrase and final_phrase for language it', function (): void {
    Http::fake(phrasesHeygenSuccess());
    Queue::fake();

    $org = phrasesOrg();
    [$project] = phrasesProjectWithCompetencies($org, 1, 'it');
    $participant = phrasesParticipant($org, $project, 'it');

    $response = phrasesStart($participant);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'question_context' => ['competency_code', 'question_index', 'end_phrase', 'final_phrase'],
    ]);

    $end = $response->json('question_context.end_phrase');
    $final = $response->json('question_context.final_phrase');

    expect($end)->toBeString()->not->toBe('');
    expect($final)->toBeString()->not->toBe('');
    expect($end)->toBe('Passiamo alla prossima domanda.');
    expect($final)->toBe('Grazie per il tuo tempo.');
});

test('POST /start question_context contains English phrases for language en, differing from Italian', function (): void {
    Http::fake(phrasesHeygenSuccess());
    Queue::fake();

    $org = phrasesOrg();
    [$project] = phrasesProjectWithCompetencies($org, 1, 'en');
    $participant = phrasesParticipant($org, $project, 'en');

    $response = phrasesStart($participant);

    $response->assertStatus(201);

    $end = $response->json('question_context.end_phrase');
    $final = $response->json('question_context.final_phrase');

    expect($end)->toBeString()->not->toBe('');
    expect($final)->toBeString()->not->toBe('');

    // English strings must NOT be the Italian ones (proves per-language resolution).
    expect($end)->not->toBe('Passiamo alla prossima domanda.');
    expect($final)->not->toBe('Grazie per il tuo tempo.');
});

test('POST /start falls back to platform default language when participant language lacks a phrase file', function (): void {
    Http::fake(phrasesHeygenSuccess());
    Queue::fake();

    // French has no lang/fr/interview.php yet → must fall back to config app.fallback_locale (en).
    $org = phrasesOrg();
    [$project] = phrasesProjectWithCompetencies($org, 1, 'fr');
    $participant = phrasesParticipant($org, $project, 'fr');

    $response = phrasesStart($participant);

    $response->assertStatus(201);

    $end = $response->json('question_context.end_phrase');
    $final = $response->json('question_context.final_phrase');

    // Present + non-null (absent field is a contract violation per delta spec).
    expect($end)->toBeString()->not->toBe('');
    expect($final)->toBeString()->not->toBe('');

    // Fallback resolves to the platform default language (en) — must equal the EN strings.
    $fallbackLocale = config('app.fallback_locale');
    expect($end)->toBe(trans('interview.end_phrase', [], $fallbackLocale));
    expect($final)->toBe(trans('interview.final_phrase', [], $fallbackLocale));
});

test('POST /start question_context end_phrase and final_phrase are present even when participant language is null', function (): void {
    Http::fake(phrasesHeygenSuccess());
    Queue::fake();

    $org = phrasesOrg();
    [$project] = phrasesProjectWithCompetencies($org, 1, 'en');
    $participant = phrasesParticipant($org, $project, null);

    $response = phrasesStart($participant);

    $response->assertStatus(201);

    expect($response->json('question_context.end_phrase'))->toBeString()->not->toBe('');
    expect($response->json('question_context.final_phrase'))->toBeString()->not->toBe('');
});
