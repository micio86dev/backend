<?php

declare(strict_types=1);

/**
 * `answer_min_seconds` / `answer_max_seconds` on avatar templates: the range a "good" answer lasts,
 * shown to the candidate as guidance only (answer-time-range). Defaults 90 and 150; the range is
 * 10..600 with min < max, on every write surface, and travels with duplicate and import/export.
 */

use App\Actions\Interview\BuildInterviewSessionResponse;
use App\Models\AvatarTemplate;
use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\Provider\ProviderToken;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

dataset('atrSurfaces', ['organization', 'platform']);

/**
 * @return array{0: string, 1: string, 2: Organization}
 */
function atrSurface(string $surface): array
{
    $org = Organization::factory()->create();

    return $surface === 'organization'
        ? ['/api/avatar-templates', TemplateActors::token('acting', $org), $org]
        : ['/api/admin/avatar-templates', TemplateActors::token('bare', $org), $org];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function atrPayload(array $extra = []): array
{
    return array_merge([
        'name' => 'Range '.uniqid(),
        'provider' => 'heygen',
        'config' => ['avatarId' => 'av_1', 'voiceId' => 'vo_1'],
    ], $extra);
}

/**
 * @param  array<string, mixed>  $columns
 */
function atrSeed(string $surface, Organization $org, array $columns = []): AvatarTemplate
{
    if ($surface === 'platform') {
        return PlatformTemplates::insertGlobal($columns);
    }

    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create(array_merge([
        'name' => 'Seed '.uniqid(),
        'provider' => 'heygen',
        'config' => ['avatarId' => 'av', 'voiceId' => 'vo'],
    ], $columns)));
}

test('a template defaults to a 90 to 150 second range, platform rows included', function (string $surface): void {
    [$url, $token, $org] = atrSurface($surface);
    $template = atrSeed($surface, $org);

    expect($template->fresh()->answer_min_seconds)->toBe(90)
        ->and($template->fresh()->answer_max_seconds)->toBe(150);

    $this->withToken($token)->getJson("{$url}/{$template->id}")->assertOk()
        ->assertJsonPath('data.answer_min_seconds', 90)
        ->assertJsonPath('data.answer_max_seconds', 150);
})->with('atrSurfaces');

test('a create without the range stores the defaults and a create with it stores the values', function (string $surface): void {
    [$url, $token] = atrSurface($surface);

    $this->withToken($token)->postJson($url, atrPayload())->assertCreated()
        ->assertJsonPath('data.answer_min_seconds', 90)
        ->assertJsonPath('data.answer_max_seconds', 150);

    $this->withToken($token)->postJson($url, atrPayload(['answer_min_seconds' => 60, 'answer_max_seconds' => 120]))->assertCreated()
        ->assertJsonPath('data.answer_min_seconds', 60)
        ->assertJsonPath('data.answer_max_seconds', 120);
})->with('atrSurfaces');

test('the range is refused when out of bounds, not an integer or not min below max', function (string $surface, array $range, string $field): void {
    [$url, $token] = atrSurface($surface);

    $this->withToken($token)->postJson($url, atrPayload($range))
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with('atrSurfaces')->with([
    'min under 10' => [['answer_min_seconds' => 9], 'answer_min_seconds'],
    'max over 600' => [['answer_max_seconds' => 601], 'answer_max_seconds'],
    'not an integer' => [['answer_min_seconds' => 'soon'], 'answer_min_seconds'],
    'min equal to max' => [['answer_min_seconds' => 100, 'answer_max_seconds' => 100], 'answer_max_seconds'],
    'min above max' => [['answer_min_seconds' => 200, 'answer_max_seconds' => 100], 'answer_max_seconds'],
    'min above the default max' => [['answer_min_seconds' => 150], 'answer_max_seconds'],
    'max under the default min' => [['answer_max_seconds' => 90], 'answer_max_seconds'],
]);

test('an update compares a single field against the stored one', function (string $surface): void {
    [$url, $token, $org] = atrSurface($surface);
    $template = atrSeed($surface, $org);

    $this->withToken($token)->patchJson("{$url}/{$template->id}", ['answer_min_seconds' => 200])
        ->assertUnprocessable()->assertJsonValidationErrors(['answer_max_seconds']);
    $this->withToken($token)->patchJson("{$url}/{$template->id}", ['answer_max_seconds' => 60])
        ->assertUnprocessable()->assertJsonValidationErrors(['answer_max_seconds']);

    $this->withToken($token)->patchJson("{$url}/{$template->id}", ['answer_min_seconds' => 45])->assertOk()
        ->assertJsonPath('data.answer_min_seconds', 45)
        ->assertJsonPath('data.answer_max_seconds', 150);
    $this->withToken($token)->patchJson("{$url}/{$template->id}", ['answer_min_seconds' => 200, 'answer_max_seconds' => 300])->assertOk()
        ->assertJsonPath('data.answer_min_seconds', 200)
        ->assertJsonPath('data.answer_max_seconds', 300);
})->with('atrSurfaces');

test('the database refuses a range the application let through', function (): void {
    $org = Organization::factory()->create();

    expect(fn () => atrSeed('platform', $org, ['answer_min_seconds' => 200, 'answer_max_seconds' => 100]))
        ->toThrow(QueryException::class);
});

test('duplicating a template copies the range', function (): void {
    $source = Organization::factory()->create();
    $target = Organization::factory()->create();
    $original = atrSeed('organization', $source, ['answer_min_seconds' => 30, 'answer_max_seconds' => 75]);

    $this->withToken(TemplateActors::token('bare', $source))
        ->postJson("/api/avatar-templates/{$original->id}/duplicate", ['target_organization_ids' => [$target->id]])
        ->assertCreated();

    $copy = TenantContextScope::runFor($target->id, fn () => AvatarTemplate::query()->firstOrFail());
    expect($copy->answer_min_seconds)->toBe(30)->and($copy->answer_max_seconds)->toBe(75);
});

test('export carries the range and import restores it', function (): void {
    $org = Organization::factory()->create();
    $token = TemplateActors::token('acting', $org);
    atrSeed('organization', $org, ['answer_min_seconds' => 30, 'answer_max_seconds' => 75]);

    $document = $this->withToken($token)->getJson('/api/avatar-templates/export')->assertOk()->json();
    expect($document['templates'][0])->toMatchArray(['answer_min_seconds' => 30, 'answer_max_seconds' => 75]);

    $document['templates'][0]['name'] = 'Round trip';
    $this->withToken($token)->postJson('/api/avatar-templates/import', $document)->assertCreated();

    $copy = AvatarTemplate::withoutGlobalScopes()->where('name', 'Round trip')->firstOrFail();
    expect($copy->answer_min_seconds)->toBe(30)->and($copy->answer_max_seconds)->toBe(75);
});

test('an older file without the range imports with the defaults and a bad range is refused', function (): void {
    $org = Organization::factory()->create();
    $token = TemplateActors::token('acting', $org);
    $entry = ['name' => 'Old', 'provider' => 'heygen', 'config' => ['avatarId' => 'av_1', 'voiceId' => 'vo_1']];

    $this->withToken($token)->postJson('/api/avatar-templates/import', ['schema' => 'beai.avatar-template/1', 'templates' => [$entry]])->assertCreated();
    $old = AvatarTemplate::withoutGlobalScopes()->where('name', 'Old')->firstOrFail();
    expect($old->answer_min_seconds)->toBe(90)->and($old->answer_max_seconds)->toBe(150);

    $bad = array_merge($entry, ['name' => 'Bad', 'answer_min_seconds' => 500, 'answer_max_seconds' => 20]);
    $this->withToken($token)->postJson('/api/avatar-templates/import', ['schema' => 'beai.avatar-template/1', 'templates' => [$bad]])->assertUnprocessable();
    expect(AvatarTemplate::withoutGlobalScopes()->where('name', 'Bad')->exists())->toBeFalse();
});

/**
 * @param  array<string, mixed>  $columns
 */
function atrSession(Organization $org, ?array $columns): InterviewSession
{
    // `null` pins a template of ANOTHER provider, so nothing resolves for the tavus session
    // (a project always pins some template; the resolver ignores one of the wrong provider).
    $template = PlatformTemplates::insertActiveGlobal(array_merge([
        'provider' => $columns === null ? 'heygen' : 'tavus',
        'config' => ['avatarId' => 'av', 'voiceId' => 'vo'],
    ], $columns ?? []));

    return TenantContextScope::runFor($org->id, function () use ($org, $template): InterviewSession {
        $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'framework_version_id' => $fv->id,
            'avatar_template_id' => $template->id,
        ]);
        $participant = Participant::factory()->create(['organization_id' => $org->id, 'project_id' => $project->id]);

        return InterviewSession::factory()->create([
            'organization_id' => $org->id,
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'framework_version_id' => $fv->id,
            'provider' => 'tavus',
        ]);
    });
}

test('the session response carries the template range, or 90 and 150 without a template', function (?array $columns, int $min, int $max): void {
    $org = Organization::factory()->create();
    $session = atrSession($org, $columns);

    $body = TenantContextScope::runFor($org->id, fn () => app(BuildInterviewSessionResponse::class)->handle(
        $session,
        new ProviderToken(provider: 'tavus', conversation_url: 'https://example.test/room'),
        'it',
    ))->getData(true);

    expect($body)->toHaveKey('answer_min_seconds', $min)->toHaveKey('answer_max_seconds', $max);
})->with([
    'custom template' => [['answer_min_seconds' => 60, 'answer_max_seconds' => 120], 60, 120],
    'default template' => [[], 90, 150],
    'no template' => [null, 90, 150],
]);
