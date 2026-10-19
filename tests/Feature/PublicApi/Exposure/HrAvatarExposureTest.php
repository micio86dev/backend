<?php

declare(strict_types=1);

/**
 * `T-EXPOSE-001` for the conditional HR-Avatar fields (hr-avatar-adapter A3b, design D-EXPOSE).
 *
 * `hr_avatar_config_id` and the `hr_avatar` start-response object exist only when a value exists, so the
 * frozen admin-vs-public diff (which runs on fixtures where they are absent) cannot see them. Each one is
 * classified in `ExposureCatalogue::conditionalExclusions()`: the keys a surface gains when the value is
 * present equal the classified list exactly, and none of them is ever public.
 */

use App\Http\Resources\AvatarTemplateResource;
use App\Http\Resources\PlatformAvatarTemplateResource;
use App\Models\Organization;
use App\PublicApi\Serializers\InterviewSerializer;
use App\PublicApi\Serializers\ProjectSerializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;
use Tests\Helpers\PublicApi\ExposureCatalogue;

uses(RefreshDatabase::class);

/** The keys `$with` has and `$without` lacks, sorted. */
function hreGained(array $with, array $without): array
{
    $gained = array_values(array_diff(ExposureCatalogue::flattenKeys($with), ExposureCatalogue::flattenKeys($without)));
    sort($gained);

    return $gained;
}

/** @return list<string> */
function hreClassified(string $surface): array
{
    $expected = ExposureCatalogue::conditionalExclusions()[$surface];
    sort($expected);

    return $expected;
}

test('T-EXPOSE-001: AvatarTemplate — a config id adds exactly the classified admin-only key', function (): void {
    $org = Organization::factory()->create();
    $linked = hraSeedTemplate($org, ['name' => 'Linked']);
    $bare = hraSeedTemplate($org, ['name' => 'Bare', 'hr_avatar_config_id' => null]);

    expect(hreGained(
        (new AvatarTemplateResource($linked))->toArray(request()),
        (new AvatarTemplateResource($bare))->toArray(request()),
    ))->toBe(hreClassified('AvatarTemplate'));
});

test('T-EXPOSE-001: PlatformAvatarTemplate — the inherited key is classified, and a platform row can be forced to carry it', function (): void {
    $usage = ['organization_count' => 1, 'project_count' => 1];
    $bare = PlatformTemplates::insertGlobal();
    // Query builder, because no endpoint can set the id on a platform template.
    $linked = PlatformTemplates::insertGlobal(['hr_avatar_config_id' => 'avc_PLATFORM']);

    expect(hreGained(
        (new PlatformAvatarTemplateResource($linked, $usage))->toArray(request()),
        (new PlatformAvatarTemplateResource($bare, $usage))->toArray(request()),
    ))->toBe(hreClassified('PlatformAvatarTemplate'));
});

test('T-EXPOSE-001: InterviewStart — an hr_avatar start gains exactly the classified join inputs over the recorded heygen start', function (): void {
    hraMakeAvailable();
    Queue::fake();
    Http::fake(['hra.example.test/v1/conversations' => Http::response(['id' => 'conv_01ABC', 'client_secret' => 'CS_VALUE'], 201)]);
    [, , , $token] = hraStartScenario('hr_avatar', ['hr_avatar_config_id' => 'avc_01HXYZ']);

    $hrAvatar = test()->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/start')->assertCreated()->json();
    // The body a non-hr_avatar start returns, recorded on the unmodified base (see HrAvatarDefaultPathInvarianceTest).
    $without = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/hr_avatar/start-heygen.json'), true, 512, JSON_THROW_ON_ERROR);

    $gained = array_values(array_filter(hreGained($hrAvatar, $without), fn (string $key): bool => str_starts_with($key, 'hr_avatar')));
    expect($gained)->toBe(hreClassified('InterviewStart'));
});

test('T-EXPOSE-001: the public API never carries a config id or the join inputs', function (): void {
    [, $project, $participant] = hraStartScenario('hr_avatar', ['hr_avatar_config_id' => 'avc_01HXYZ']);

    $public = array_merge(
        ExposureCatalogue::flattenKeys(ProjectSerializer::toArray($project->fresh(['frameworkVersion', 'avatarTemplate', 'competencies']))),
        ExposureCatalogue::flattenKeys(InterviewSerializer::toArray($participant->fresh())),
    );

    expect(array_values(array_filter($public, fn (string $key): bool => str_contains($key, 'hr_avatar'))))->toBe([]);
    expect(json_encode([ProjectSerializer::toArray($project->fresh(['frameworkVersion', 'avatarTemplate', 'competencies'])), InterviewSerializer::toArray($participant->fresh())]))
        ->not->toContain('avc_01HXYZ');
});

test('T-EXPOSE-001: the published /v1 document names neither the config id nor the join inputs', function (): void {
    $document = file_get_contents(base_path('openapi.v1.json'));

    expect($document)->not->toContain('hr_avatar_config_id')->and($document)->not->toContain('hr_avatar');
});
