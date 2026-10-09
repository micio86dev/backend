<?php

declare(strict_types=1);

/**
 * db-driven-conversation-prompts PR8: POST /candidate/interview/start composes
 * its prompt from the ACTIVE stored prompt set.
 *
 * What is proved here, with real rows and the real request path:
 *  - the database path is LIVE (a set whose text differs reaches the provider),
 *  - a missing, ambiguous, tampered or incomplete set is a HARD failure: the
 *    existing 422 `composition_error`, no session row and no provider call, with
 *    no fallback to the baseline text,
 *  - the `conversation.prompt_source = baseline` break-glass composes the code
 *    baseline and never reads the tables,
 *  - an unknown source value fails loudly instead of choosing a source.
 *
 * Byte identity of the stored baseline set against the fixtures is proved by
 * `InterviewStartPromptGoldenTest` (both sources) and `SystemPromptGoldenTest`.
 */

use App\Actions\Conversation\ActivatePromptSet;
use App\Actions\Conversation\PublishPromptSet;
use App\Exceptions\Conversation\PromptTemplateUnresolvableException;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\ConversationPromptSet;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\Role;
use App\Services\Conversation\PromptSetResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\Conversation\PromptSetPayload as Payload;
use Tests\Helpers\Conversation\PromptTables;

/** The stored baseline set as the bootstrap migration left it: the reference the stamp must name. */
function cutoverActiveRef(): string
{
    $set = ConversationPromptSet::query()->where('is_active', true)->sole();

    return sprintf('s%d.%s', $set->id, substr($set->content_sha256, 0, 12));
}

const CUTOVER_MARKER = 'CUTOVER-MARKER: ask at most {{budget}} follow-up questions.';

beforeEach(function (): void {
    config(['conversation.min_questions' => 4, 'conversation.followup_budget' => 4]);
    Http::fake(heygenOkFake());
    Queue::fake();
    PromptSetResolver::flushCache();
});

/** A standard project with one interviewable competency, and its fresh participant. */
function cutoverParticipant(string $locale = 'en'): Participant
{
    $org = casOrg();

    $project = casInTenant($org, fn (): Project => Project::factory()->create([
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'CUT_ROLE',
        'language' => $locale,
        'nudge_min_chars' => 120,
    ]));
    $role = Role::factory()->create(['code' => 'CUT_ROLE']);
    $competency = Competency::factory()->create(['code' => 'CUT_COMP']);

    DB::table('project_competencies')->insert(['project_id' => $project->id, 'competency_id' => $competency->id, 'position' => 0]);

    foreach ([0, 1] as $i) {
        $indicator = new BarsIndicator;
        $indicator->forceFill([
            'role_id' => $role->id,
            'competency_id' => $competency->id,
            'text' => ['en' => "indicator {$i}", 'it' => "indicatore {$i}"],
            'anchor_5' => ['en' => "five {$i}", 'it' => "cinque {$i}"],
            'anchor_3' => ['en' => "three {$i}", 'it' => "tre {$i}"],
            'anchor_1' => ['en' => "one {$i}", 'it' => "uno {$i}"],
            'position' => $i,
        ]);
        $indicator->save();
    }

    ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'Tell me about a conflict.', 'it' => 'Raccontami di un conflitto.'],
        'position' => 0,
    ]);

    return casParticipant($org, $project, 'in_attesa');
}

function cutoverStart(Participant $participant): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])->postJson('/api/candidate/interview/start');
}

/** A second, fresh candidate of the same project as `$first`. */
function cutoverNextCandidate(Participant $first): Participant
{
    $org = Organization::query()->findOrFail($first->organization_id);

    return casInTenant($org, fn (): Participant => casParticipant($org, Project::query()->findOrFail($first->project_id), 'in_attesa'));
}

/** The `prompt` of the most recent provider context creation of this test. */
function cutoverPrompt(): string
{
    $creations = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/contexts'))
        ->values();

    expect($creations)->not->toBeEmpty();

    return (string) ($creations->last()->data()['prompt'] ?? '');
}

/** Publish a set whose `budget` fragment is distinguishable, and make it the active one. */
function cutoverActivateMarkerSet(string $label = 'cutover-marker', array $locales = ['en', 'it']): ConversationPromptSet
{
    $set = app(PublishPromptSet::class)->handle($label, null, Payload::fragments($locales, ['budget' => CUTOVER_MARKER]));
    app(ActivatePromptSet::class)->handle($label);

    return $set;
}

/** Nothing may have been created or sent: the hard-failure contract. */
function cutoverAssertRefused(TestResponse $response): void
{
    $response->assertStatus(422)->assertExactJson(['error' => 'composition_error']);

    expect(InterviewSession::query()->count())->toBe(0);
    Http::assertNothingSent();
}

test('the prompt the provider receives is composed from the active stored set', function (): void {
    cutoverActivateMarkerSet();

    cutoverStart(cutoverParticipant())->assertStatus(201);

    expect(cutoverPrompt())->toContain('CUTOVER-MARKER: ask at most 4 follow-up questions.');
});

test('activating another set changes the prompt of the next start, with no cache flush', function (): void {
    cutoverActivateMarkerSet('cutover-first');
    $first = cutoverParticipant();
    cutoverStart($first)->assertStatus(201);

    expect(cutoverPrompt())->toContain('CUTOVER-MARKER');

    app(PublishPromptSet::class)->handle('cutover-second', null, Payload::fragments(['en', 'it'], ['budget' => 'SECOND-SET: at most {{budget}} follow-ups.']));
    app(ActivatePromptSet::class)->handle('cutover-second');

    cutoverStart(cutoverNextCandidate($first))->assertStatus(201);

    expect(cutoverPrompt())->toContain('SECOND-SET: at most 4 follow-ups.')->not->toContain('CUTOVER-MARKER');
});

test('no active set is a hard 422 composition_error with no session and no provider call', function (): void {
    PromptTables::empty();

    cutoverAssertRefused(cutoverStart(cutoverParticipant()));
});

test('a tampered active set is refused the same way, and never falls back to the baseline text', function (): void {
    $participant = cutoverParticipant();
    DB::statement('ALTER TABLE conversation_prompt_fragments DISABLE TRIGGER USER');
    DB::table('conversation_prompt_fragments')->where('fragment_key', 'budget')->update(['body' => 'TAMPERED {{budget}}']);
    DB::statement('ALTER TABLE conversation_prompt_fragments ENABLE TRIGGER USER');

    cutoverAssertRefused(cutoverStart($participant));
});

test('two active sets are refused the same way', function (): void {
    $participant = cutoverParticipant();
    DB::statement('DROP INDEX conversation_prompt_sets_one_active');
    app(PublishPromptSet::class)->handle('cutover-second', null, Payload::fragments());
    ConversationPromptSet::query()->where('label', 'cutover-second')->update(['is_active' => true]);

    cutoverAssertRefused(cutoverStart($participant));
});

test('an active set without the project language is refused the same way', function (): void {
    cutoverActivateMarkerSet('cutover-en-only', ['en']);

    cutoverAssertRefused(cutoverStart(cutoverParticipant('it')));
});

test('the refusal is reported, so an operator sees a broken active set', function (): void {
    Exceptions::fake();
    PromptTables::empty();

    cutoverStart(cutoverParticipant())->assertStatus(422);

    Exceptions::assertReported(PromptTemplateUnresolvableException::class);
});

test('the baseline source composes the code baseline and works with no stored set at all', function (): void {
    config(['conversation.prompt_source' => 'baseline']);
    PromptTables::empty();

    cutoverStart(cutoverParticipant())->assertStatus(201);

    expect(cutoverPrompt())->toContain('Ask at most 4 follow-up questions per competency.');
});

test('the baseline source never reads the stored set, even a distinguishable active one', function (): void {
    cutoverActivateMarkerSet();
    config(['conversation.prompt_source' => 'baseline']);

    cutoverStart(cutoverParticipant())->assertStatus(201);

    expect(cutoverPrompt())->not->toContain('CUTOVER-MARKER');
});

test('an unknown prompt source fails loudly and chooses neither source', function (): void {
    Exceptions::fake();
    config(['conversation.prompt_source' => 'bogus']);

    cutoverAssertRefused(cutoverStart(cutoverParticipant()));

    Exceptions::assertReported(fn (PromptTemplateUnresolvableException $e): bool => $e->reason === PromptTemplateUnresolvableException::INVALID_SOURCE
        && str_contains($e->getMessage(), 'bogus')
        && str_contains($e->getMessage(), 'db')
        && str_contains($e->getMessage(), 'baseline'));
});

// ─── The durable stamp ───────────────────────────────────────────────────────

test('the session stamp names the configured version and the stored set that was composed', function (): void {
    $participant = cutoverParticipant();
    $ref = cutoverActiveRef();

    $response = cutoverStart($participant)->assertStatus(201);

    $stamp = InterviewSession::query()->where('participant_id', $participant->id)->sole()->conversation_prompt_version;

    expect($stamp)->toBe(config('conversation.prompt_version').'+'.$ref)
        ->and($stamp)->toMatch('/^[^+]+\+s\d+\.[0-9a-f]{12}$/')
        // The client-facing version is the bare configured string: the set reference stays internal.
        ->and($response->json('question_context.prompt_version'))->toBe(config('conversation.prompt_version'));
});

test('the baseline source stamps the bare configured version', function (): void {
    config(['conversation.prompt_source' => 'baseline']);
    $participant = cutoverParticipant();

    cutoverStart($participant)->assertStatus(201);

    expect(InterviewSession::query()->where('participant_id', $participant->id)->sole()->conversation_prompt_version)
        ->toBe(config('conversation.prompt_version'));
});

test('activating another set after the interview was composed does not change its stamp', function (): void {
    $participant = cutoverParticipant();
    $firstStamp = config('conversation.prompt_version').'+'.cutoverActiveRef();

    cutoverStart($participant)->assertStatus(201);

    $second = cutoverActivateMarkerSet('cutover-later');
    $this->assertNotSame($firstStamp, config('conversation.prompt_version').'+s'.$second->id.'.'.substr($second->content_sha256, 0, 12));

    // The same candidate resumes: the prompt really IS composed again, from the new set.
    cutoverStart($participant)->assertStatus(201);

    expect(cutoverPrompt())->toContain('CUTOVER-MARKER')
        ->and(InterviewSession::query()->where('participant_id', $participant->id)->sole()->conversation_prompt_version)->toBe($firstStamp);
});
