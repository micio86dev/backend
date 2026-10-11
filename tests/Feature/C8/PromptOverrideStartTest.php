<?php

declare(strict_types=1);

/**
 * db-driven-conversation-prompts PR9: an override published in a prompt set
 * reaches the prompt POST /candidate/interview/start sends to the provider.
 *
 * The operator path is the real one: a JSON file through `beai:prompt-set:publish`,
 * then `beai:prompt-set:activate`, then a candidate starts. What is proved:
 *  - the override of the started competency is printed once, between COVERAGE
 *    TOPICS and the STAR protocol, and the session stamp names that set,
 *  - a role-specific override beats a role-less one and they are never combined,
 *    an override for another role or locale never applies, and a `potential`
 *    project (no role) only ever sees role-less rows,
 *  - a competency without an override is composed exactly as before while its
 *    sibling has one,
 *  - the `baseline` source never prints an override, and a placeholder smuggled
 *    into a stored override is refused as the usual 422 composition_error.
 *
 * Which bytes an override adds is proved in `PromptOverrideRenderingTest`; which
 * row applies, in `PromptSetResolverTest`.
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\ConversationPromptSet;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\Role;
use App\Services\Conversation\PromptSetResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\Conversation\PromptSetPayload as Payload;
use Tests\Helpers\Conversation\PromptTables;

const OVRS_LABEL = 'COMPETENCY-SPECIFIC GUIDANCE:';

beforeEach(function (): void {
    config(['conversation.min_questions' => 4, 'conversation.followup_budget' => 4]);
    Http::fake(heygenOkFake());
    Queue::fake();
    PromptSetResolver::flushCache();
});

/**
 * One project per competency code, all on the same role, each with a fresh participant.
 *
 * @param  list<string>  $competencyCodes
 * @return array<string, Participant>
 */
function ovrsParticipants(array $competencyCodes, string $assessment = 'standard', string $locale = 'en'): array
{
    $org = casOrg();
    $role = $assessment === 'standard' ? Role::factory()->create(['code' => 'OVR_ROLE']) : null;
    $participants = [];

    foreach ($competencyCodes as $code) {
        $project = casInTenant($org, fn (): Project => Project::factory()->create([
            'status' => 'active',
            'assessment_type' => $assessment,
            // A potential project carries no role by rule; the stale code is what a wrong lookup would find.
            'role_code' => 'OVR_ROLE',
            'language' => $locale,
            'nudge_min_chars' => 120,
        ]));
        $competency = Competency::factory()->create(['code' => $code]);

        DB::table('project_competencies')->insert(['project_id' => $project->id, 'competency_id' => $competency->id, 'position' => 0]);

        $indicator = new BarsIndicator;
        $indicator->forceFill([
            'role_id' => $role?->id,
            'competency_id' => $competency->id,
            'text' => ['en' => 'indicator', 'it' => 'indicatore'],
            'anchor_5' => ['en' => 'five', 'it' => 'cinque'],
            'anchor_3' => ['en' => 'three', 'it' => 'tre'],
            'anchor_1' => ['en' => 'one', 'it' => 'uno'],
            'position' => 0,
        ]);
        $indicator->save();

        ProjectQuestion::create(['project_id' => $project->id, 'competency_id' => $competency->id, 'text' => ['en' => 'Tell me.', 'it' => 'Dimmi.'], 'position' => 0]);

        $participants[$code] = casParticipant($org, $project, 'in_attesa');
    }

    return $participants;
}

/**
 * The operator path: publish a JSON file, then activate it by label.
 *
 * @param  list<array{role_code: string|null, competency_code: string, locale: string, body: string}>  $overrides
 */
function ovrsPublishAndActivate(array $overrides, string $label = 'ovrs-set'): ConversationPromptSet
{
    $file = Payload::writeFile(['label' => $label, 'notes' => null, 'fragments' => Payload::byLocale(), 'overrides' => $overrides]);

    expect(Artisan::call('beai:prompt-set:publish', ['file' => $file]))->toBe(0)
        ->and(Artisan::call('beai:prompt-set:activate', ['label' => $label]))->toBe(0);

    return ConversationPromptSet::query()->where('label', $label)->sole();
}

function ovrsStart(Participant $participant): TestResponse
{
    // One test starts several candidates: drop the guard's cached user or every request is the first one.
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])->postJson('/api/candidate/interview/start');
}

/** The `prompt` of the most recent provider context creation of this test. */
function ovrsPrompt(): string
{
    $creations = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/contexts'))
        ->values();

    expect($creations)->not->toBeEmpty();

    return (string) ($creations->last()->data()['prompt'] ?? '');
}

/** Start the participant and return the prompt the provider received. */
function ovrsStartedPrompt(Participant $participant): string
{
    ovrsStart($participant)->assertStatus(201);

    return ovrsPrompt();
}

test('a published and activated override reaches the prompt, once, between COVERAGE TOPICS and the STAR protocol', function (): void {
    $participants = ovrsParticipants(['OVR_A']);
    $set = ovrsPublishAndActivate([Payload::override('OVR_ROLE', 'OVR_A', 'en', 'Probe for a measurable outcome.')]);

    $prompt = ovrsStartedPrompt($participants['OVR_A']);

    expect(substr_count($prompt, OVRS_LABEL."\nProbe for a measurable outcome.\n\n"))->toBe(1)
        ->and(substr_count($prompt, 'Probe for a measurable outcome.'))->toBe(1)
        ->and(strpos($prompt, 'COVERAGE TOPICS'))->toBeLessThan((int) strpos($prompt, OVRS_LABEL))
        ->and(strpos($prompt, OVRS_LABEL))->toBeLessThan((int) strpos($prompt, 'STAR COVERAGE PROTOCOL'))
        ->and(InterviewSession::query()->sole()->conversation_prompt_version)
        ->toBe(config('conversation.prompt_version').sprintf('+s%d.%s', $set->id, substr($set->content_sha256, 0, 12)));
});

test('a role-specific override beats a role-less one and the two are never combined', function (): void {
    $participants = ovrsParticipants(['OVR_A']);
    ovrsPublishAndActivate([
        Payload::override(null, 'OVR_A', 'en', 'ROLE-LESS body.'),
        Payload::override('OVR_ROLE', 'OVR_A', 'en', 'ROLE-SPECIFIC body.'),
    ]);

    $prompt = ovrsStartedPrompt($participants['OVR_A']);

    expect($prompt)->toContain("ROLE-SPECIFIC body.\n\n")
        ->not->toContain('ROLE-LESS body.')
        ->and(substr_count($prompt, OVRS_LABEL))->toBe(1);
});

test('a role-less override applies to the role, and an override for another role or locale does not', function (): void {
    $participants = ovrsParticipants(['OVR_A', 'OVR_B']);
    ovrsPublishAndActivate([
        Payload::override(null, 'OVR_A', 'en', 'ROLE-LESS body.'),
        Payload::override('OTHER_ROLE', 'OVR_B', 'en', 'OTHER-ROLE body.'),
        Payload::override(null, 'OVR_B', 'it', 'TESTO ITALIANO.'),
    ]);

    expect(ovrsStartedPrompt($participants['OVR_A']))->toContain("ROLE-LESS body.\n\n");

    $other = ovrsStartedPrompt($participants['OVR_B']);

    expect($other)->not->toContain(OVRS_LABEL)->not->toContain('OTHER-ROLE body.')->not->toContain('TESTO ITALIANO.');
});

test('a competency without an override is composed untouched while its sibling has one', function (): void {
    $participants = ovrsParticipants(['OVR_A', 'OVR_B']);
    ovrsPublishAndActivate([Payload::override('OVR_ROLE', 'OVR_A', 'en', 'Only for A.')]);

    $withOverride = ovrsStartedPrompt($participants['OVR_A']);
    $without = ovrsStartedPrompt($participants['OVR_B']);

    expect($withOverride)->toContain('Only for A.')
        ->and($without)->not->toContain('Only for A.')->not->toContain(OVRS_LABEL);
});

test('a potential project resolves no role, so only a role-less override applies', function (): void {
    $participants = ovrsParticipants(['OVR_P1', 'OVR_P2'], assessment: 'potential');
    ovrsPublishAndActivate([
        Payload::override(null, 'OVR_P1', 'en', 'ROLE-LESS body.'),
        // Matches the stale role_code of the project; a potential assessment must never look it up.
        Payload::override('OVR_ROLE', 'OVR_P2', 'en', 'ROLE-SPECIFIC body.'),
    ]);

    expect(ovrsStartedPrompt($participants['OVR_P1']))->toContain("ROLE-LESS body.\n\n")
        ->and(ovrsStartedPrompt($participants['OVR_P2']))->not->toContain('ROLE-SPECIFIC body.')->not->toContain(OVRS_LABEL);
});

test('the override follows the project language', function (): void {
    $participants = ovrsParticipants(['OVR_A'], locale: 'it');
    ovrsPublishAndActivate([
        Payload::override(null, 'OVR_A', 'en', 'ENGLISH body.'),
        Payload::override(null, 'OVR_A', 'it', 'Testo italiano con caffè e 日本語.'),
    ]);

    expect(ovrsStartedPrompt($participants['OVR_A']))->toContain("Testo italiano con caffè e 日本語.\n\n")->not->toContain('ENGLISH body.');
});

test('the baseline source never prints an override, even from an active set that carries one', function (): void {
    $participants = ovrsParticipants(['OVR_A']);
    ovrsPublishAndActivate([Payload::override('OVR_ROLE', 'OVR_A', 'en', 'Never printed.')]);
    config(['conversation.prompt_source' => 'baseline']);

    expect(ovrsStartedPrompt($participants['OVR_A']))->not->toContain('Never printed.')->not->toContain(OVRS_LABEL);
});

test('a placeholder smuggled into a stored override is a 422 composition_error, never printed', function (): void {
    $participants = ovrsParticipants(['OVR_A']);
    $set = ovrsPublishAndActivate([Payload::override('OVR_ROLE', 'OVR_A', 'en', 'Plain text for now.')]);
    PromptTables::breakOverrideBody($set, 'Ask at most {{budget}} things.');

    ovrsStart($participants['OVR_A'])->assertStatus(422)->assertExactJson(['error' => 'composition_error']);

    expect(InterviewSession::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('publishing a file whose override carries a placeholder exits non-zero and stores nothing', function (): void {
    $before = ConversationPromptSet::query()->count();
    $file = Payload::writeFile([
        'label' => 'ovrs-bad', 'notes' => null, 'fragments' => Payload::byLocale(),
        'overrides' => [Payload::override(null, 'OVR_A', 'en', 'Ask at most {{budget}} things.')],
    ]);

    expect(Artisan::call('beai:prompt-set:publish', ['file' => $file]))->toBe(1)
        ->and(ConversationPromptSet::query()->count())->toBe($before);
});
