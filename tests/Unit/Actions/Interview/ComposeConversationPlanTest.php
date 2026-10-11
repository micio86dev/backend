<?php

declare(strict_types=1);

/**
 * RED/GREEN — tavus-single-session-interview API-03b.1: `ComposeConversationPlan`.
 *
 * Resolves, per remaining competency and through the SAME action the single-competency
 * path uses (`ComposeCompetencyPrompt::resolveInput()`), the pinned revision, the role
 * (null for `potential`), the authored primaries, the spoken opening, the advance phrase and
 * the stored set with its override, then hands the list to `composeMany()`. Every entry must
 * report the same `stampRef()`: a flip of the active set between two resolutions fails the
 * whole create as a `CompositionException` (the controller's 422 `composition_error`) before
 * any session or provider call.
 */

use App\Actions\Interview\ComposeConversationPlan;
use App\DTOs\Conversation\ConversationPlan;
use App\Enums\AssessmentType;
use App\Exceptions\Conversation\CompositionException;
use App\Exceptions\Conversation\PromptTemplateUnresolvableException;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\Role;
use App\Services\Conversation\PromptSetResolver;
use App\Support\Catalogue\CatalogueRevisionResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Conversation\PromptSetPayload as Payload;
use Tests\Helpers\Conversation\PromptTables;

beforeEach(function (): void {
    PromptSetResolver::flushCache();
});

/**
 * A project with the given competencies (code => authored primaries), their indicators and questions.
 *
 * @param  array<string, list<string>>  $competencies
 * @return array{0: Project, 1: int, 2: list<array{competency_code: string, competency_ordinal: int, total_competencies: int}>}
 */
function ccplFixture(array $competencies, string $assessment = 'standard'): array
{
    $org = casOrg();
    $role = $assessment === 'standard' ? Role::factory()->create(['code' => 'CCPL_ROLE']) : null;

    $project = casInTenant($org, fn (): Project => Project::factory()->create([
        'status' => 'active',
        'assessment_type' => $assessment,
        'role_code' => 'CCPL_ROLE',
        'language' => 'en',
    ]));

    $remaining = [];
    $position = 0;

    foreach ($competencies as $code => $primaries) {
        $competency = Competency::factory()->create(['code' => $code]);
        $indicator = new BarsIndicator;
        $indicator->forceFill([
            'role_id' => $role?->id,
            'competency_id' => $competency->id,
            'text' => ['en' => "text {$code}"],
            'anchor_5' => ['en' => "five {$code}"],
            'anchor_3' => ['en' => "three {$code}"],
            'anchor_1' => ['en' => "one {$code}"],
            'position' => 0,
        ]);
        $indicator->save();

        foreach ($primaries as $i => $question) {
            ProjectQuestion::create(['project_id' => $project->id, 'competency_id' => $competency->id, 'text' => ['en' => $question], 'position' => $i]);
        }

        DB::table('project_competencies')->insert(['project_id' => $project->id, 'competency_id' => $competency->id, 'position' => $position]);
        $remaining[] = ['competency_code' => $code, 'competency_ordinal' => ++$position, 'total_competencies' => count($competencies)];
    }

    $revisionId = app(CatalogueRevisionResolver::class)->tryForProject($project);
    expect($revisionId)->not->toBeNull();

    return [$project, $revisionId, $remaining];
}

/** @param  list<array{competency_code: string, competency_ordinal: int, total_competencies: int}>  $remaining */
function ccplCompose(Project $project, ?int $revisionId, array $remaining, AssessmentType $type = AssessmentType::Standard): ConversationPlan
{
    return app(ComposeConversationPlan::class)->handle($project, $type, $revisionId, $remaining, 4, 'END-PHRASE-X', 'FINAL-PHRASE-X');
}

function ccplPublishAndActivate(string $label, array $overrides = []): void
{
    $file = Payload::writeFile(['label' => $label, 'notes' => null, 'fragments' => Payload::byLocale(), 'overrides' => $overrides]);

    expect(Artisan::call('beai:prompt-set:publish', ['file' => $file]))->toBe(0);
    expect(Artisan::call('beai:prompt-set:activate', ['label' => $label]))->toBe(0);
}

test('it resolves every competency and composes one segmented plan with the frozen entries', function (): void {
    config(['conversation.prompt_source' => 'baseline']);
    [$project, $revisionId, $remaining] = ccplFixture(['CCPL_A' => ['Question A1.', 'Question A2.'], 'CCPL_B' => ['Question B1.']]);

    $plan = ccplCompose($project, $revisionId, $remaining);

    expect($plan->coveredCodes)->toBe(['CCPL_A', 'CCPL_B'])
        ->and($plan->text)->toContain('=== TOPIC CODE: CCPL_A ===', '=== TOPIC CODE: CCPL_B ===', 'Question A1.', 'Question B1.')
        ->and($plan->stored())->toBe([
            'competencies' => [
                ['code' => 'CCPL_A', 'primary_questions' => ['Question A1.', 'Question A2.'], 'follow_up_budget' => 4],
                ['code' => 'CCPL_B', 'primary_questions' => ['Question B1.'], 'follow_up_budget' => 4],
            ],
            'chars' => $plan->chars,
        ]);
});

test('only the last competency of the project carries the final phrase, even when the plan is a prefix', function (): void {
    config(['conversation.prompt_source' => 'baseline']);
    [$project, $revisionId, $remaining] = ccplFixture(['CCPL_A' => ['A?'], 'CCPL_B' => ['B?'], 'CCPL_C' => ['C?']]);

    $full = ccplCompose($project, $revisionId, $remaining);
    $prefix = ccplCompose($project, $revisionId, array_slice($remaining, 0, 2));

    expect(substr_count($full->text, 'FINAL-PHRASE-X'))->toBeGreaterThan(0)
        ->and(substr_count($full->text, 'END-PHRASE-X'))->toBeGreaterThan(0)
        ->and($prefix->text)->not->toContain('FINAL-PHRASE-X')
        ->and($prefix->coveredCodes)->toBe(['CCPL_A', 'CCPL_B']);
});

test('a potential project resolves no role and composes against the role-less rows', function (): void {
    config(['conversation.prompt_source' => 'baseline']);
    [$project, $revisionId, $remaining] = ccplFixture(['CCPL_P1' => ['P1?'], 'CCPL_P2' => ['P2?']], 'potential');

    $plan = ccplCompose($project, $revisionId, $remaining, AssessmentType::Potential);

    expect($plan->coveredCodes)->toBe(['CCPL_P1', 'CCPL_P2'])->and($plan->text)->toContain('five CCPL_P1', 'five CCPL_P2');
});

test('the baseline source composes with no stored set and no override', function (): void {
    config(['conversation.prompt_source' => 'baseline']);
    [$project, $revisionId, $remaining] = ccplFixture(['CCPL_A' => ['A?'], 'CCPL_B' => ['B?']]);

    $plan = ccplCompose($project, $revisionId, $remaining);

    expect($plan->promptSetRef)->toBeNull();
});

test('the db source stamps ONE stored set and applies each competency override only inside its own segment', function (): void {
    config(['conversation.prompt_source' => 'db']);
    [$project, $revisionId, $remaining] = ccplFixture(['CCPL_A' => ['A?'], 'CCPL_B' => ['B?']]);
    ccplPublishAndActivate('ccpl-ovr', [Payload::override('CCPL_ROLE', 'CCPL_A', 'en', 'Override only for A.')]);

    $plan = ccplCompose($project, $revisionId, $remaining);
    [$segmentA, $segmentB] = explode('=== TOPIC CODE: CCPL_B ===', $plan->text);

    expect($plan->promptSetRef)->toMatch('/^s\d+\.[0-9a-f]{12}$/')
        ->and($segmentA)->toContain('Override only for A.')
        ->and($segmentB)->not->toContain('Override only for A.');
});

test('a flip of the active set between two resolutions fails the create as a composition error', function (): void {
    config(['conversation.prompt_source' => 'db']);
    [$project, $revisionId, $remaining] = ccplFixture(['CCPL_A' => ['A?'], 'CCPL_B' => ['B?']]);
    ccplPublishAndActivate('ccpl-first');
    $file = Payload::writeFile(['label' => 'ccpl-second', 'notes' => null, 'fragments' => Payload::byLocale(), 'overrides' => []]);
    expect(Artisan::call('beai:prompt-set:publish', ['file' => $file]))->toBe(0);

    $flipped = false;
    DB::listen(function ($query) use (&$flipped): void {
        if (! $flipped && str_contains($query->sql, 'conversation_prompt_sets') && str_starts_with($query->sql, 'select')) {
            $flipped = true;
            Artisan::call('beai:prompt-set:activate', ['label' => 'ccpl-second']);
        }
    });

    expect(fn () => ccplCompose($project, $revisionId, $remaining))->toThrow(CompositionException::class, 'same prompt set');
});

test('an unresolvable active set keeps its own exception so the controller can report it', function (): void {
    config(['conversation.prompt_source' => 'db']);
    [$project, $revisionId, $remaining] = ccplFixture(['CCPL_A' => ['A?'], 'CCPL_B' => ['B?']]);
    PromptTables::empty();

    expect(fn () => ccplCompose($project, $revisionId, $remaining))->toThrow(PromptTemplateUnresolvableException::class);
});

test('any covered competency failing fails the whole plan', function (): void {
    config(['conversation.prompt_source' => 'baseline']);
    [$project, $revisionId, $remaining] = ccplFixture(['CCPL_A' => ['A?'], 'CCPL_B' => ['B?']]);
    $remaining[] = ['competency_code' => 'CCPL_MISSING', 'competency_ordinal' => 3, 'total_competencies' => 3];

    expect(fn () => ccplCompose($project, $revisionId, $remaining))->toThrow(CompositionException::class);
});

test('an unresolved catalogue revision fails the plan', function (): void {
    [$project, , $remaining] = ccplFixture(['CCPL_A' => ['A?'], 'CCPL_B' => ['B?']]);

    expect(fn () => ccplCompose($project, null, $remaining))->toThrow(CompositionException::class);
});
