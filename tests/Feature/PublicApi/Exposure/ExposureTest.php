<?php

declare(strict_types=1);

/**
 * `T-EXPOSE-001` / `T-EXPOSE-002` — BEAI Public API (public-api step 4),
 * SPEC.md §3.4 "Data exposure rules (the 'everything the admin sees'
 * contract)".
 */

use App\Enums\ApiKeyMode;
use App\Http\Resources\Admin\EvaluationResource as AdminEvaluationResource;
use App\Http\Resources\Admin\OrganizationResource as AdminOrganizationResource;
use App\Http\Resources\Admin\ParticipantDetailResource as AdminParticipantDetailResource;
use App\Http\Resources\Admin\ParticipantResource as AdminParticipantResource;
use App\Http\Resources\Admin\TranscriptResource as AdminTranscriptResource;
use App\Http\Resources\AvatarTemplateResource;
use App\Http\Resources\ProjectResource as AdminProjectResource;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\InterviewEvent;
use App\Models\InterviewRecording;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\PublicApi\Serializers\InterviewSerializer;
use App\PublicApi\Serializers\OrganizationSerializer;
use App\PublicApi\Serializers\ProjectSerializer;
use App\PublicApi\Serializers\ScoringSerializer;
use App\PublicApi\Serializers\TranscriptSerializer;
use App\Services\Admin\AdminEvaluationSerializer;
use App\Services\Admin\AdminTranscript;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\PublicApi\ExposureCatalogue;
use Tests\Helpers\PublicApi\Step6Fixtures;

// ─── T-EXPOSE-001: field-diff test ───────────────────────────────────────────

test('T-EXPOSE-001: Organization — admin minus public equals exactly the frozen exclusion list, public minus admin equals exactly the frozen addition list', function (): void {
    $org = Organization::factory()->create([
        'default_webhook_url' => 'https://example.test/hook',
        'default_webhook_secret' => 'secret',
        'default_webhook_events' => ['progress'],
        'logo_path' => 'orgs/1/logo.png',
        'primary_color' => '#112233',
        'allowed_domains' => ['acme.example'],
    ]);

    $adminKeys = ExposureCatalogue::flattenKeys((new AdminOrganizationResource($org))->toArray(request()));
    $publicKeys = ExposureCatalogue::flattenKeys(OrganizationSerializer::toArray($org, ApiKeyMode::Live));

    $exclusions = array_values(array_diff($adminKeys, $publicKeys));
    $additions = array_values(array_diff($publicKeys, $adminKeys));

    sort($exclusions);
    sort($additions);
    $expectedExclusions = ExposureCatalogue::exclusions()['Organization'];
    $expectedAdditions = ExposureCatalogue::additions()['Organization'];
    sort($expectedExclusions);
    sort($expectedAdditions);

    expect($exclusions)->toBe($expectedExclusions);
    expect($additions)->toBe($expectedAdditions);
});

test('T-EXPOSE-001: Project (+ its nested avatar template) — admin minus public equals exactly the frozen exclusion list, public minus admin equals exactly the frozen addition list', function (): void {
    $org = Organization::factory()->create();

    TenantContextScope::runFor($org->id, function () use ($org): void {
        $frameworkVersion = FrameworkVersion::factory()->create(['version' => '2026.1', 'label' => 'Autumn 2026']);
        $avatarTemplate = AvatarTemplate::create([
            'name' => 'Ada',
            'provider' => 'tavus',
            'description' => 'A Tavus-backed avatar',
            'config' => ['persona_id' => 'persona-123'],
            'is_active' => true,
        ]);

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'framework_version_id' => $frameworkVersion->id,
            'avatar_template_id' => $avatarTemplate->id,
            'webhook_url' => 'https://example.test/hook',
            'webhook_secret' => 'secret',
            'error_redirect_url' => 'https://example.test/error',
            'deadline_at' => now(),
            'goes_live_at' => now(),
        ]);

        $competency = Competency::query()->where('code', 'COM')->first()
            ?? Competency::factory()->create(['code' => 'COM']);
        $project->competencies()->attach($competency->id, ['position' => 1]);
        $project = $project->fresh(['frameworkVersion', 'avatarTemplate', 'competencies']);

        $adminProjectKeys = ExposureCatalogue::flattenKeys((new AdminProjectResource($project))->toArray(request()));
        $adminProjectKeysWithoutAvatar = array_values(array_filter(
            $adminProjectKeys,
            fn (string $key): bool => ! str_starts_with($key, 'avatar_template.'),
        ));

        /** @var AvatarTemplate $avatar */
        $avatar = $project->avatarTemplate;
        $avatarKeys = array_map(
            fn (string $key): string => 'avatar_template.'.$key,
            ExposureCatalogue::flattenKeys((new AvatarTemplateResource($avatar))->toArray(request())),
        );

        $adminKeys = array_values(array_unique(array_merge($adminProjectKeysWithoutAvatar, $avatarKeys)));
        $publicKeys = ExposureCatalogue::flattenKeys(ProjectSerializer::toArray($project));

        $exclusions = array_values(array_diff($adminKeys, $publicKeys));
        $additions = array_values(array_diff($publicKeys, $adminKeys));

        sort($exclusions);
        sort($additions);
        $expectedExclusions = ExposureCatalogue::exclusions()['Project'];
        $expectedAdditions = ExposureCatalogue::additions()['Project'];
        sort($expectedExclusions);
        sort($expectedAdditions);

        expect($exclusions)->toBe($expectedExclusions);
        expect($additions)->toBe($expectedAdditions);
    });
});

test('T-EXPOSE-001: Interview — admin (list ∪ detail) minus public equals exactly the frozen exclusion list, public minus admin equals exactly the frozen addition list', function (): void {
    $org = Organization::factory()->create();

    TenantContextScope::runFor($org->id, function () use ($org): void {
        $avatarTemplate = AvatarTemplate::create(['name' => 'Ada', 'provider' => 'heygen', 'config' => []]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'avatar_template_id' => $avatarTemplate->id,
        ]);

        $competency = Competency::query()->where('code', 'COM')->first()
            ?? Competency::factory()->create(['code' => 'COM']);
        $project->competencies()->attach($competency->id, ['position' => 1]);

        $participant = Participant::factory()->forProject($project)->create([
            'organization_id' => $org->id,
            // Empty metadata flattens to the bare `metadata` leaf — see
            // ExposureCatalogue's own "list of scalars/empty list" rule.
            'metadata' => null,
        ])->refresh();

        $adminListKeys = ExposureCatalogue::flattenKeys((new AdminParticipantResource($participant))->toArray(request()));
        $adminDetailKeys = ExposureCatalogue::flattenKeys((new AdminParticipantDetailResource($participant))->toArray(request()));
        $adminKeys = array_values(array_unique(array_merge($adminListKeys, $adminDetailKeys)));

        $publicKeys = ExposureCatalogue::flattenKeys(InterviewSerializer::toArray($participant));

        $exclusions = array_values(array_diff($adminKeys, $publicKeys));
        $additions = array_values(array_diff($publicKeys, $adminKeys));

        sort($exclusions);
        sort($additions);
        $expectedExclusions = ExposureCatalogue::exclusions()['Interview'];
        $expectedAdditions = ExposureCatalogue::additions()['Interview'];
        sort($expectedExclusions);
        sort($expectedAdditions);

        expect($exclusions)->toBe($expectedExclusions);
        expect($additions)->toBe($expectedAdditions);
    });
});

test('T-EXPOSE-001: Transcript — admin minus public equals exactly the frozen exclusion list, public minus admin equals exactly the frozen addition list', function (): void {
    $dto = new AdminTranscript(
        isPartial: false,
        sessions: [[
            'session_id' => 1,
            'competency_code' => 'COL',
            'question_index' => 0,
            'utterances' => [['speaker' => 'avatar', 'text' => 'hi', 'ts' => '2026-01-01T00:00:00Z']],
        ]],
    );
    $adminKeys = ExposureCatalogue::flattenKeys((new AdminTranscriptResource($dto))->toArray(request()));

    ['org' => $org] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $publicKeys = TenantContextScope::runFor(
        $org->id,
        fn () => ExposureCatalogue::flattenKeys(TranscriptSerializer::toArray($participant)),
    );

    $exclusions = array_values(array_diff($adminKeys, $publicKeys));
    $additions = array_values(array_diff($publicKeys, $adminKeys));

    sort($exclusions);
    sort($additions);
    $expectedExclusions = ExposureCatalogue::exclusions()['Transcript'];
    $expectedAdditions = ExposureCatalogue::additions()['Transcript'];
    sort($expectedExclusions);
    sort($expectedAdditions);

    expect($exclusions)->toBe($expectedExclusions);
    expect($additions)->toBe($expectedAdditions);
});

test('T-EXPOSE-001: Scoring — admin (evaluation ∪ meta) minus public equals exactly the frozen exclusion list, public minus admin equals exactly the frozen addition list', function (): void {
    ['org' => $org] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::buildCompletedScoredParticipant($org, $project);

    $adminSerializer = new AdminEvaluationSerializer;
    $adminData = TenantContextScope::runFor($org->id, fn () => $adminSerializer->serialize($participant));
    $adminMeta = TenantContextScope::runFor($org->id, fn () => $adminSerializer->meta($participant));
    $adminAuditMeta = TenantContextScope::runFor($org->id, fn () => $adminSerializer->auditMeta($participant));

    $adminResource = new AdminEvaluationResource($adminData, $adminMeta, $adminAuditMeta);
    $adminFull = array_merge($adminResource->toArray(request()), $adminResource->with(request()));
    $adminKeys = ExposureCatalogue::flattenKeys($adminFull);

    $publicKeys = TenantContextScope::runFor(
        $org->id,
        fn () => ExposureCatalogue::flattenKeys((new ScoringSerializer)->toArray($participant)),
    );

    $exclusions = array_values(array_diff($adminKeys, $publicKeys));
    $additions = array_values(array_diff($publicKeys, $adminKeys));

    sort($exclusions);
    sort($additions);
    $expectedExclusions = ExposureCatalogue::exclusions()['Scoring'];
    $expectedAdditions = ExposureCatalogue::additions()['Scoring'];
    sort($expectedExclusions);
    sort($expectedAdditions);

    expect($exclusions)->toBe($expectedExclusions);
    expect($additions)->toBe($expectedAdditions);
});

// ─── T-EXPOSE-002: no secret-shaped value ever leaks ────────────────────────

test('T-EXPOSE-002: GET /v1/organization never contains a beai_live_/beai_test_/tavus/heygen value, even with a webhook secret configured', function (): void {
    $org = Organization::factory()->create([
        'default_webhook_secret' => 'a-very-secret-value',
        'default_webhook_url' => 'https://tavus.example/hook',
        'allowed_domains' => ['acme.example'],
    ]);
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id, 'abilities' => []]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/organization');

    $response->assertOk();
    assertNoForbiddenPattern($response->getContent());
});

test('T-EXPOSE-002: GET /v1/projects and GET /v1/projects/{id} never contain a beai_live_/beai_test_/tavus/heygen value, even on a Tavus/HeyGen-backed project with a webhook secret', function (): void {
    $org = Organization::factory()->create(['default_webhook_secret' => 'org-secret']);
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'abilities' => ['projects:read'],
    ]);

    $project = TenantContextScope::runFor($org->id, function () use ($org): Project {
        // The DISPLAY NAME deliberately does NOT mention the provider —
        // `avatar_display_name` is the one avatar-template field SPEC.md
        // §3.4 says IS public (it is an operator-chosen label, not a
        // provider identifier), so a display name containing the literal
        // word "heygen" would be a legitimate false positive against this
        // grep, not a real leak. `provider`/`config` (which DO carry
        // "heygen"/a persona id) are exactly what T-EXPOSE-001 already
        // proves excluded; this test proves those excluded values never
        // leak through ANY field, not that an operator can never choose a
        // provider-flavoured display name.
        $avatarTemplate = AvatarTemplate::create([
            'name' => 'Ada',
            'provider' => 'heygen',
            'config' => ['persona_id' => 'heygen_persona_9', 'api_key' => 'heygen_live_abc123'],
        ]);

        return Project::factory()->create([
            'organization_id' => $org->id,
            'avatar_template_id' => $avatarTemplate->id,
            'webhook_url' => 'https://tavus.example/hook',
            'webhook_secret' => 'project-secret',
        ]);
    });

    $list = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/projects');
    $list->assertOk();
    assertNoForbiddenPattern($list->getContent());

    $detail = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/projects/'.PublicId::encode($project));
    $detail->assertOk();
    assertNoForbiddenPattern($detail->getContent());
});

test('T-EXPOSE-002: POST/GET /v1/interviews never contain a beai_live_/beai_test_/tavus/heygen value, even on a Tavus/HeyGen-backed project', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);

    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'abilities' => ['interviews:write', 'interviews:read'],
    ]);

    $project = TenantContextScope::runFor($org->id, function () use ($org): Project {
        $avatarTemplate = AvatarTemplate::create([
            'name' => 'Ada',
            'provider' => 'heygen',
            'config' => ['persona_id' => 'heygen_persona_9', 'api_key' => 'heygen_live_abc123'],
        ]);

        return Project::factory()->create([
            'organization_id' => $org->id,
            'avatar_template_id' => $avatarTemplate->id,
            'status' => 'active',
            'webhook_url' => 'https://tavus.example/hook',
            'webhook_secret' => 'project-secret',
        ]);
    });

    $create = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', [
            'project_id' => PublicId::encode($project),
            'candidate' => [
                'candidate_ref' => 'expose-ref',
                'email' => 'expose@example.com',
                'display_name' => 'Expose Candidate',
            ],
        ]);

    $create->assertCreated();
    // `session_token`/`hosted_url` are genuinely present and deliberately
    // NOT scanned as leaks — they carry no `beai_live_`/`beai_test_` API-key
    // marker (a different token format, HS256 JWT vs. the key generator's
    // own prefix scheme) and no provider name.
    $interviewOnly = $create->json('interview');
    assertNoForbiddenPattern(json_encode($interviewOnly));

    $id = $create->json('interview.id');

    $detail = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.$id);
    $detail->assertOk();
    assertNoForbiddenPattern($detail->getContent());

    $list = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews');
    $list->assertOk();
    assertNoForbiddenPattern($list->getContent());
});

test('T-EXPOSE-002: GET /v1/interviews/{id}/transcript and /answers never contain a beai_live_/beai_test_/tavus/heygen value', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $transcript = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/transcript');
    $transcript->assertOk();
    assertNoForbiddenPattern($transcript->getContent());

    $answers = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/answers');
    $answers->assertOk();
    assertNoForbiddenPattern($answers->getContent());
});

test('T-EXPOSE-002: GET /v1/interviews/{id}/scoring never contains a beai_live_/beai_test_/tavus/heygen value', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::buildCompletedScoredParticipant($org, $project);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/scoring');
    $response->assertOk();
    assertNoForbiddenPattern($response->getContent());
});

test('T-EXPOSE-002: GET /v1/interviews/{id}/recording never contains a beai_live_/beai_test_/tavus/heygen value', function (): void {
    Storage::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $objectKey = 'recordings/'.$org->id.'/'.$participant->id.'/interview.ogg';
    Storage::put($objectKey, 'fake-audio-bytes');

    TenantContextScope::runFor($org->id, fn () => InterviewRecording::factory()->create([
        'participant_id' => $participant->id,
        'object_key' => $objectKey,
    ]));

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/recording');
    $response->assertOk();
    assertNoForbiddenPattern($response->getContent());
});

test('T-EXPOSE-002: GET /v1/interviews/{id}/events never contains a beai_live_/beai_test_/tavus/heygen value', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    TenantContextScope::runFor($org->id, fn () => InterviewEvent::create([
        'participant_id' => $participant->id,
        'type' => 'session_started',
        'occurred_at' => now(),
    ]));

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/events');
    $response->assertOk();
    assertNoForbiddenPattern($response->getContent());
});

/**
 * Recursively scans a JSON response body for any value matching
 * `beai_live_|beai_test_|tavus|heygen` (case-insensitive) — SPEC.md §3.4
 * "grep-style assertion". Scans VALUES only (not keys) — a key literally
 * named `avatar_display_name` is fine; a VALUE like `"heygen_persona_9"`
 * is not.
 */
function assertNoForbiddenPattern(string|false $body): void
{
    expect($body)->toBeString();
    /** @var string $body */
    $decoded = json_decode($body, true);
    expect($decoded)->toBeArray();

    $pattern = '/beai_live_|beai_test_|tavus|heygen/i';
    $offenders = [];
    scanForForbiddenPattern($decoded, $pattern, $offenders);

    expect($offenders)->toBe([], 'Forbidden values found: '.json_encode($offenders));
}

/**
 * @param  list<string>  $offenders
 */
function scanForForbiddenPattern(mixed $value, string $pattern, array &$offenders): void
{
    if (is_array($value)) {
        foreach ($value as $item) {
            scanForForbiddenPattern($item, $pattern, $offenders);
        }

        return;
    }

    if (is_string($value) && preg_match($pattern, $value) === 1) {
        $offenders[] = $value;
    }
}
