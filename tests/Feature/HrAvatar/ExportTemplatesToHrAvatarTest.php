<?php

declare(strict_types=1);

/**
 * `hr-avatar:export-templates` (hr-avatar-adapter A5): dry run by default; `--apply` creates one HR-Avatar
 * config per eligible template and writes ONLY `avatar_templates.hr_avatar_config_id`.
 */

use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Services\Provider\HrAvatarTemplateMapping;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use App\Support\Tenancy\TenantContextScope;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/** @return array{0: int, 1: string} exit code and output */
function hreRun(bool $apply = false): array
{
    $exit = Artisan::call('hr-avatar:export-templates', $apply ? ['--apply' => true] : []);

    return [$exit, Artisan::output()];
}

/** @return list<array<string, mixed>> */
function hreRows(): array
{
    return DB::table('avatar_templates')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
}

/** @param array<string, mixed> $attributes */
function hreTemplate(Organization $org, string $provider = 'tavus', array $attributes = []): int
{
    return hraSeedTemplate($org, array_merge([
        'provider' => $provider,
        'config' => $provider === 'tavus' ? ['faceId' => 'r1', 'palId' => 'p1'] : ['avatarId' => 'a1', 'voiceId' => 'v1'],
        'hr_avatar_config_id' => null,
    ], $attributes))->id;
}

function hreCreated(string $id, string $syncStatus = 'synced'): PromiseInterface
{
    return Http::response(['id' => $id, 'sync_status' => $syncStatus], 201);
}

/** @return list<array<string, mixed>> */
function hreBodies(): array
{
    return Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->values()->all();
}

// ─── Dry run ─────────────────────────────────────────────────────────────────

test('a dry run lists the plan, calls nothing, writes nothing and works while unavailable', function (): void {
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $ids = [hreTemplate($a, 'tavus', ['name' => 'Ada']), hreTemplate($a, 'heygen', ['name' => 'Bruno']), hreTemplate($b, 'tavus', ['name' => 'Chiara'])];
    $linked = hreTemplate($a, 'heygen', ['name' => 'Linked', 'hr_avatar_config_id' => 'avc_OLD']);
    hreTemplate($b, 'tavus', ['name' => 'Gone']);
    DB::table('avatar_templates')->where('name', 'Gone')->update(['deleted_at' => now()]);
    hraSeedTemplate($a, ['name' => 'Native']);
    PlatformTemplates::insertGlobal(['name' => 'Platform one']);
    $before = hreRows();

    [$exit, $output] = hreRun();

    expect($exit)->toBe(0)
        ->and(substr_count($output, 'would_create'))->toBe(4)
        ->and($output)->toContain("template={$ids[0]} org={$a->id} provider=tavus name=\"Ada\" would_create")
        ->and($output)->toContain("template={$ids[2]} org={$b->id} provider=tavus name=\"Chiara\" would_create")
        ->and($output)->toContain("template={$linked} org={$a->id} provider=heygen name=\"Linked\" already_linked")
        ->and($output)->toContain('would_create: 3')->toContain('already_linked: 1')
        ->and($output)->not->toContain('Gone')->not->toContain('Platform one')->not->toContain('Native');
    expect(hreRows())->toBe($before);
    Http::assertNothingSent();
});

// ─── Apply: availability ─────────────────────────────────────────────────────

test('--apply refuses while unavailable, names the missing variables only and changes nothing', function (): void {
    config(['hr_avatar.base_url' => 'https://hra.example.test']);
    hreTemplate(Organization::factory()->create());
    $before = hreRows();

    [$exit, $output] = hreRun(true);

    expect($exit)->toBe(1)
        ->and($output)->toContain('HR_AVATAR_SECRET_KEY')->toContain('HR_AVATAR_PUBLISHABLE_KEY')
        ->and($output)->not->toContain('hra.example.test')->not->toContain('HR_AVATAR_BASE_URL');
    expect(hreRows())->toBe($before);
    Http::assertNothingSent();
});

// ─── Apply: success ──────────────────────────────────────────────────────────

test('--apply links each template and changes no other column; each config carries its own organization', function (): void {
    hraMakeAvailable();
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    hreTemplate($a, 'tavus', ['name' => str_repeat('è', 120), 'is_active' => true]);
    hreTemplate($b, 'heygen', ['name' => 'Second']);
    Http::fake(['*' => Http::sequence()->push(['id' => 'avc_A', 'sync_status' => 'synced'], 201)->push(['id' => 'avc_B', 'sync_status' => 'synced'], 201)]);
    $before = hreRows();

    [$exit, $output] = hreRun(true);

    expect($exit)->toBe(0);
    $after = hreRows();
    expect(array_column($after, 'hr_avatar_config_id'))->toBe(['avc_A', 'avc_B']);
    foreach ($before as $i => $row) {
        expect(array_diff_assoc($after[$i], $row))->toBe(['hr_avatar_config_id' => ['avc_A', 'avc_B'][$i]]);
    }

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://hra.example.test/v1/avatar-configs'
        && ($request->header('Authorization')[0] ?? null) === 'Bearer hra_sk_live_SECRETVALUE');
    $bodies = hreBodies();
    expect($bodies[0]['external_ref'])->toBe('org_'.$a->id)
        ->and($bodies[1]['external_ref'])->toBe('org_'.$b->id)
        ->and($bodies[0]['provider'])->toBe('tavus')
        ->and($bodies[1]['provider'])->toBe('heygen')
        ->and($bodies[0]['name'])->toBe(str_repeat('è', 120));
    expect($output)->not->toContain('SECRETVALUE');
});

test('the config sent drops the voice keys, binds an external voice and carries nothing private', function (): void {
    hraMakeAvailable();
    config(['interview.heygen.api_key' => 'heygen-key-SENTINEL', 'interview.tavus.api_key' => 'tavus-key-SENTINEL']);
    $org = Organization::factory()->create();
    $model = LlmModel::create([
        'key' => 'gemini-3-flash-preview', 'vendor' => 'google', 'display_name' => 'Gemini', 'base_url' => 'https://example.test/v1beta/openai/',
        'capability' => 'text', 'is_available' => true, 'sort_order' => 0,
    ]);
    $credential = TenantContextScope::runFor($org->id, function (): LlmCredential {
        $credential = new LlmCredential;
        $credential->forceFill(['name' => 'Cred', 'vendor' => 'google', 'api_key' => 'llm-key-SENTINEL', 'key_last_four' => 'ntel', 'key_fingerprint' => hash('sha256', 'x')]);
        $credential->save();

        return $credential;
    });
    hreTemplate($org, 'heygen', [
        'name' => 'Bound',
        'config' => ['avatarId' => 'a1', 'voiceId' => 'v1', 'ttsEngine' => 'elevenlabs', 'ttsExternalVoiceId' => 'ext_1', 'voiceSpeed' => 1.1],
        'persona' => ['display_name' => 'Zed-DISPLAY-NAME'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]);
    hreTemplate($org, 'tavus', ['name' => 'Plain', 'config' => ['faceId' => 'r1', 'palId' => 'p1', 'ttsEngine' => 'tavus-auto', 'ttsExternalVoiceId' => 'ignored']]);
    Http::fake(['*' => hreCreated('avc_X')]);

    hreRun(true);

    [$bound, $plain] = hreBodies();
    expect($bound['config'])->toEqual(['avatarId' => 'a1', 'voiceSpeed' => 1.1])
        ->and($bound['voice'])->toBe(['engine' => 'elevenlabs', 'voice_id' => 'ext_1'])
        ->and($plain['config'])->toEqual(['replica_id' => 'r1', 'persona_id' => 'p1'])
        ->and($plain)->not->toHaveKey('voice');
    $sent = json_encode(hreBodies(), JSON_THROW_ON_ERROR);
    expect($sent)->not->toContain('SENTINEL')->not->toContain('Zed-DISPLAY-NAME')->not->toContain('"persona"')
        ->not->toContain('llm_model_id')->not->toContain('llm_credential_id')->not->toContain('gemini');
});

// ─── sync_status ─────────────────────────────────────────────────────────────

test('the sync status of every created config is printed, counted and warned about', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    foreach (['one', 'two', 'three'] as $name) {
        hreTemplate($org, 'tavus', ['name' => $name]);
    }
    Http::fake(['*' => Http::sequence()
        ->push(['id' => 'avc_A', 'sync_status' => 'synced'], 201)
        ->push(['id' => 'avc_B', 'sync_status' => 'pending'], 201)
        ->push(['id' => 'avc_C', 'sync_status' => 'PENDING; rm'], 201)]);

    [$exit, $output] = hreRun(true);

    expect($exit)->toBe(0)
        ->and($output)->toContain('created avc_A sync_status=synced')
        ->and($output)->toContain('created avc_B sync_status=pending')
        ->and($output)->toContain('created avc_C sync_status=unknown')
        ->and($output)->not->toContain('PENDING; rm')
        ->and($output)->toContain('synced=1')->toContain('pending=1')->toContain('unknown=1')
        ->and($output)->toContain('pending')->toContain('cannot start a conversation');
    expect(array_column(hreRows(), 'hr_avatar_config_id'))->toBe(['avc_A', 'avc_B', 'avc_C']);
});

test('a sync status or an id with a trailing newline is not trusted', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    hreTemplate($org, 'tavus', ['name' => 'status']);
    hreTemplate($org, 'tavus', ['name' => 'id']);
    Http::fake(['*' => Http::sequence()
        ->push(['id' => 'avc_A', 'sync_status' => "synced\n"], 201)
        ->push(['id' => "avc_B\n", 'sync_status' => 'synced'], 201)]);

    [$exit, $output] = hreRun(true);

    expect($exit)->toBe(1)
        ->and($output)->toContain('created avc_A sync_status=unknown')
        ->and($output)->toContain('name="id" failed provider_bad_response');
    expect(array_column(hreRows(), 'hr_avatar_config_id'))->toBe(['avc_A', null]);
});

// ─── Failures ────────────────────────────────────────────────────────────────

test('an upstream failure is reported by a stable code, writes nothing and leaks nothing', function (int $status, string $code): void {
    hraMakeAvailable();
    hreTemplate(Organization::factory()->create());
    Http::fake(['*' => $status === 0
        ? fn () => throw new ConnectionException('upstream-detail')
        : Http::response('upstream-detail hra_sk_live_SECRETVALUE', $status)]);

    [$exit, $output] = hreRun(true);

    expect($exit)->toBe(1)
        ->and($output)->toContain("failed {$code}")
        ->and($output)->not->toContain('SECRETVALUE')->not->toContain('upstream-detail');
    expect(DB::table('avatar_templates')->value('hr_avatar_config_id'))->toBeNull();
})->with([
    '401' => [401, 'provider_unauthorized'],
    '403 insufficient_scope' => [403, 'provider_unauthorized'],
    '429' => [429, 'provider_rate_limited'],
    '503' => [503, 'provider_unavailable'],
    '422' => [422, 'provider_rejected'],
    'unreachable' => [0, 'provider_unreachable'],
]);

test('a returned id outside the avc_ format is a bad response and is never written', function (mixed $body): void {
    hraMakeAvailable();
    hreTemplate(Organization::factory()->create());
    Http::fake(['*' => Http::response($body, 201)]);

    [$exit, $output] = hreRun(true);

    expect($exit)->toBe(1)->and($output)->toContain('failed provider_bad_response');
    expect(DB::table('avatar_templates')->value('hr_avatar_config_id'))->toBeNull();
})->with([
    'wrong prefix' => [['id' => 'cfg_1']],
    'illegal characters' => [['id' => 'avc_a b']],
    'too long' => [['id' => 'avc_'.str_repeat('a', 61)]],
    'not a string' => [['id' => 7]],
    'missing' => [['sync_status' => 'synced']],
    'not an object' => ['upstream-detail'],
]);

test('one rejection does not stop the rest, the exit status is non-zero', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    hreTemplate($org, 'tavus', ['name' => 'first']);
    hreTemplate($org, 'tavus', ['name' => 'second']);
    Http::fake(['*' => Http::sequence()->push(['message' => 'no'], 422)->push(['id' => 'avc_B', 'sync_status' => 'synced'], 201)]);

    [$exit, $output] = hreRun(true);

    expect($exit)->toBe(1)->and($output)->toContain('failed provider_rejected')->toContain('created avc_B');
    expect(array_column(hreRows(), 'hr_avatar_config_id'))->toBe([null, 'avc_B']);
});

// ─── Idempotence and concurrency ─────────────────────────────────────────────

test('a second apply creates nothing for a linked template', function (): void {
    hraMakeAvailable();
    hreTemplate(Organization::factory()->create());
    Http::fake(['*' => hreCreated('avc_A')]);

    hreRun(true);
    [$exit, $output] = hreRun(true);

    expect($exit)->toBe(0)->and($output)->toContain('already_linked');
    Http::assertSentCount(1);
});

test('an id set while the request was in flight is never overwritten', function (): void {
    hraMakeAvailable();
    hreTemplate(Organization::factory()->create());
    Http::fake(function (Request $request) {
        DB::table('avatar_templates')->update(['hr_avatar_config_id' => 'avc_OTHER']);

        return hreCreated('avc_MINE');
    });

    [$exit, $output] = hreRun(true);

    expect($exit)->toBe(1)->and($output)->toContain('failed write_conflict orphaned_config=avc_MINE');
    expect(DB::table('avatar_templates')->value('hr_avatar_config_id'))->toBe('avc_OTHER');
});

// ─── Key mapping (A6) ────────────────────────────────────────────────────────

/** The keys HR-Avatar accepts, copied from its field specs (slice 15, `TavusFieldSpecs` / `LiveAvatarFieldSpecs`). */
const HRE_HR_AVATAR_KEYS = [
    'tavus' => [
        'config' => ['replica_id', 'persona_id', 'participant_left_timeout', 'tts_model_name'],
        'persona' => ['llm_temperature', 'llm_speculative_inference', 'turn_taking_patience', 'interruptibility', 'voice_isolation', 'idle_engagement'],
    ],
    'heygen' => [
        'config' => ['avatarId', 'interactivityType', 'videoQuality', 'voiceSpeed', 'voiceStability', 'voiceSimilarityBoost', 'voiceStyle', 'voiceUseSpeakerBoost', 'ttsModelName'],
        'persona' => [],
    ],
];

test('parity: every BEAI provider key is mapped, a voice key or reported as dropped, and every target is a key HR-Avatar accepts', function (string $provider): void {
    $beai = array_map(fn ($spec): string => $spec->key, ProviderFieldSpecs::for($provider));
    $accounted = array_merge(
        array_keys(HrAvatarTemplateMapping::KEYS[$provider]),
        HrAvatarTemplateMapping::VOICE_KEYS[$provider],
        HrAvatarTemplateMapping::DROPPED[$provider],
    );

    expect(array_values(array_diff($beai, $accounted)))->toBe([]);
    expect(array_values(array_diff($accounted, $beai)))->toBe([]);

    foreach (HrAvatarTemplateMapping::KEYS[$provider] as [$member, $target]) {
        expect(HRE_HR_AVATAR_KEYS[$provider][$member])->toContain($target);
    }
    // HR-Avatar keys no BEAI key reaches would be dead entries in this table's reference list.
    $targets = array_map(fn (array $row): string => $row[1], HrAvatarTemplateMapping::KEYS[$provider]);
    expect(array_values(array_diff(array_merge(...array_values(HRE_HR_AVATAR_KEYS[$provider])), $targets)))
        ->toBe($provider === 'tavus' ? ['participant_left_timeout'] : []);
})->with(['tavus', 'heygen']);

/**
 * The keys a HR-Avatar field specs source declares, per member: every `new FieldSpec('<key>'` of `config()`
 * and of `persona()`. Read straight from the source so the copied list above cannot drift from it unnoticed.
 *
 * @return array{config: list<string>, persona: list<string>}
 */
function hreSourceKeys(string $file): array
{
    [$config, $persona] = array_pad(explode('function persona(', (string) file_get_contents($file), 2), 2, '');
    $keys = static fn (string $part): array => preg_match_all("/new FieldSpec\(\s*'([^']+)'/", $part, $matches) > 0 ? $matches[1] : [];

    return ['config' => $keys($config), 'persona' => $keys($persona)];
}

test('the copied HR-Avatar key list equals what the HR-Avatar field specs source accepts', function (string $provider, string $relativePath): void {
    $file = base_path('../hr-avatar/service/app/AvatarProviders/'.$relativePath);

    if (! is_file($file)) {
        $this->markTestSkipped('The sibling checkout ../hr-avatar/service is absent (CI and bare clones), so the copied key list cannot be compared with its source.');
    }

    $source = hreSourceKeys($file);

    expect($source['config'])->not->toBeEmpty();
    expect($source['config'])->toEqualCanonicalizing(HRE_HR_AVATAR_KEYS[$provider]['config']);
    expect($source['persona'])->toEqualCanonicalizing(HRE_HR_AVATAR_KEYS[$provider]['persona']);
})->with([
    'tavus' => ['tavus', 'Tavus/TavusFieldSpecs.php'],
    'heygen' => ['heygen', 'LiveAvatar/LiveAvatarFieldSpecs.php'],
]);

test('a Tavus template is exported with the replica and persona ids, the persona knobs split out and the voice bound', function (): void {
    hraMakeAvailable();
    hreTemplate(Organization::factory()->create(), 'tavus', ['config' => [
        'faceId' => 'r1', 'palId' => 'p1', 'ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => 'ext_1', 'ttsModelName' => 'sonic-3',
        'llmTemperature' => 0.4, 'llmSpeculativeInference' => true, 'turnTakingPatience' => 'high', 'interruptibility' => 'low',
        'voiceIsolation' => 'near', 'idleEngagement' => 'patient',
        'audioOnly' => false, 'maxCallDurationSec' => 600, 'participantAbsentTimeoutSec' => 60, 'enableRecording' => true, 'enableClosedCaptions' => true,
    ]]);
    Http::fake(['*' => hreCreated('avc_A')]);

    [$exit, $output] = hreRun(true);

    [$body] = hreBodies();
    expect($exit)->toBe(0)
        ->and($body['config'])->toEqual(['replica_id' => 'r1', 'persona_id' => 'p1', 'tts_model_name' => 'sonic-3'])
        ->and($body['persona'])->toEqual([
            'llm_temperature' => 0.4, 'llm_speculative_inference' => true, 'turn_taking_patience' => 'high',
            'interruptibility' => 'low', 'voice_isolation' => 'near', 'idle_engagement' => 'patient',
        ])
        ->and($body['voice'])->toBe(['engine' => 'cartesia', 'voice_id' => 'ext_1'])
        ->and($output)->toContain('created avc_A sync_status=synced dropped_key=audioOnly,enableClosedCaptions,enableRecording,maxCallDurationSec,participantAbsentTimeoutSec')
        ->and($output)->toContain('dropped_key: 1');
});

test('a HeyGen template keeps its settings, binds the stock voice and reports the keys HR-Avatar has no counterpart for', function (): void {
    hraMakeAvailable();
    hreTemplate(Organization::factory()->create(), 'heygen', ['config' => [
        'avatarId' => 'a1', 'voiceId' => 'v1', 'interactivityType' => 'PUSH_TO_TALK', 'videoQuality' => 'high',
        'maxSessionDurationSec' => 900, 'videoEncoding' => 'VP8',
    ]]);
    Http::fake(['*' => hreCreated('avc_A')]);

    [$exit, $output] = hreRun(true);

    [$body] = hreBodies();
    expect($exit)->toBe(0)
        ->and($body['config'])->toEqual(['avatarId' => 'a1', 'interactivityType' => 'PUSH_TO_TALK', 'videoQuality' => 'high'])
        ->and($body['voice'])->toBe(['engine' => 'provider', 'voice_id' => 'v1'])
        ->and($body)->not->toHaveKey('persona')
        ->and($output)->toContain('dropped_key=maxSessionDurationSec,videoEncoding');
});

test('a setting that needs an external voice is dropped, and reported, when none is bound or the engine does not take it', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    hreTemplate($org, 'heygen', ['name' => 'stock', 'config' => ['avatarId' => 'a1', 'voiceId' => 'v1', 'voiceSpeed' => 1.1, 'ttsModelName' => 'sonic-3']]);
    hreTemplate($org, 'heygen', ['name' => 'cartesia', 'config' => ['avatarId' => 'a1', 'ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => 'x', 'voiceSpeed' => 1.1, 'voiceStability' => 0.5]]);
    hreTemplate($org, 'tavus', ['name' => 'auto', 'config' => ['faceId' => 'r', 'palId' => 'p', 'ttsEngine' => 'tavus-auto', 'ttsModelName' => 'sonic-3']]);
    hreTemplate($org, 'tavus', ['name' => 'azure', 'config' => ['faceId' => 'r', 'palId' => 'p', 'ttsEngine' => 'azure', 'ttsExternalVoiceId' => 'az']]);
    Http::fake(['*' => hreCreated('avc_A')]);

    [$exit, $output] = hreRun(true);

    [$stock, $cartesia, $auto, $azure] = hreBodies();
    expect($exit)->toBe(0)
        ->and($stock['config'])->toEqual(['avatarId' => 'a1'])
        ->and($cartesia['config'])->toEqual(['avatarId' => 'a1', 'voiceSpeed' => 1.1])
        ->and($auto['config'])->toEqual(['replica_id' => 'r', 'persona_id' => 'p'])
        ->and($azure)->not->toHaveKey('voice')
        ->and($output)->toContain('dropped_key=ttsModelName,voiceSpeed')
        ->and($output)->toContain('dropped_key=voiceStability')
        ->and($output)->toContain('dropped_key=ttsModelName')
        ->and($output)->toContain('dropped_key=ttsEngine,ttsExternalVoiceId');
});

test('a HeyGen stock voice id that an external voice replaces is reported as dropped, and reported only then', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    hreTemplate($org, 'heygen', ['name' => 'external', 'config' => ['avatarId' => 'a1', 'voiceId' => 'v1', 'ttsEngine' => 'elevenlabs', 'ttsExternalVoiceId' => 'ext_1']]);
    hreTemplate($org, 'heygen', ['name' => 'stock', 'config' => ['avatarId' => 'a1', 'voiceId' => 'v1']]);
    Http::fake(['*' => hreCreated('avc_A')]);

    [$exit, $output] = hreRun(true);

    [$external, $stock] = hreBodies();
    expect($exit)->toBe(0)
        ->and($external['voice'])->toBe(['engine' => 'elevenlabs', 'voice_id' => 'ext_1'])
        ->and($stock['voice'])->toBe(['engine' => 'provider', 'voice_id' => 'v1'])
        ->and($output)->toContain('name="external" created avc_A sync_status=synced dropped_key=voiceId')
        ->and($output)->not->toContain('name="stock" created avc_A sync_status=synced dropped_key');
});

test('the mapping reports the replaced stock voice id and nothing else for an external voice', function (): void {
    $mapped = HrAvatarTemplateMapping::map('heygen', ['avatarId' => 'a1', 'voiceId' => 'v1', 'ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => 'x']);

    expect($mapped['dropped'])->toBe(['voiceId'])
        ->and($mapped['voice'])->toBe(['engine' => 'cartesia', 'voice_id' => 'x']);
    expect(HrAvatarTemplateMapping::map('heygen', ['avatarId' => 'a1', 'voiceId' => 'v1'])['dropped'])->toBe([]);
});

test('the mapping reports the stock voice id too when the external voice has no id to bind', function (): void {
    $mapped = HrAvatarTemplateMapping::map('heygen', ['avatarId' => 'a1', 'voiceId' => 'v1', 'ttsEngine' => 'elevenlabs']);

    expect($mapped['voice_missing'])->toBeTrue()
        ->and($mapped['voice'])->toBeNull()
        ->and($mapped['dropped'])->toBe(['voiceId']);
});

test('the mapping reports every key as dropped for a provider it has no table for', function (): void {
    $mapped = HrAvatarTemplateMapping::map('mock', ['alpha' => 1, 'beta' => 2]);

    expect($mapped['dropped'])->toBe(['alpha', 'beta'])
        ->and($mapped['config'])->toBe([])
        ->and($mapped['voice'])->toBeNull();
});

test('a key nobody mapped is reported as dropped and never sent', function (): void {
    hraMakeAvailable();
    hreTemplate(Organization::factory()->create(), 'tavus', ['config' => ['faceId' => 'r', 'palId' => 'p', 'brandNewKey' => 1]]);
    Http::fake(['*' => hreCreated('avc_A')]);

    [, $output] = hreRun(true);

    expect(hreBodies()[0]['config'])->toEqual(['replica_id' => 'r', 'persona_id' => 'p'])
        ->and($output)->toContain('dropped_key=brandNewKey');
});

test('a dry run reports the dropped keys too, without any request', function (): void {
    hreTemplate(Organization::factory()->create(), 'heygen', ['config' => ['avatarId' => 'a1', 'voiceId' => 'v1', 'videoEncoding' => 'H264']]);

    [$exit, $output] = hreRun();

    expect($exit)->toBe(0)->and($output)->toContain('would_create dropped_key=videoEncoding');
    Http::assertNothingSent();
});

// ─── R3-001: an external engine without its voice id ─────────────────────────

test('an external engine without a voice id is reported as voice_missing, counted, never created and exits non-zero', function (bool $apply, mixed $voiceId): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    $config = ['avatarId' => 'a1', 'voiceId' => 'v1', 'ttsEngine' => 'elevenlabs'] + ($voiceId === false ? [] : ['ttsExternalVoiceId' => $voiceId]);
    hreTemplate($org, 'heygen', ['name' => 'Broken', 'config' => $config]);
    hreTemplate($org, 'tavus', ['name' => 'Fine']);
    Http::fake(['*' => hreCreated('avc_OK')]);

    [$exit, $output] = hreRun($apply);

    expect($exit)->toBe(1)
        ->and($output)->toContain('name="Broken" failed voice_missing')
        ->and($output)->toContain('failed: 1');
    expect(array_column(hreRows(), 'hr_avatar_config_id'))->toBe([null, $apply ? 'avc_OK' : null]);
    $apply ? Http::assertSentCount(1) : Http::assertNothingSent();
})->with([
    'apply, id absent' => [true, false],
    'apply, id empty' => [true, ''],
    'dry run, id null' => [false, null],
]);

// ─── R3-002: a timeout may have created the config ───────────────────────────

test('an unreachable upstream is ambiguous: reported as such with the advice, sent once, never retried', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    hreTemplate($org);
    $attempts = 0;
    Http::fake(['*' => function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('upstream-detail');
    }]);

    [$exit, $output] = hreRun(true);

    expect($attempts)->toBe(1)
        ->and($exit)->toBe(1)
        ->and($output)->toContain('failed provider_unreachable_ambiguous')
        ->and($output)->toContain('may have created')->toContain('external_ref=org_'.$org->id)
        ->and($output)->not->toContain('upstream-detail');
});

// ─── R3-003: the link write may fail after the config was created ────────────

test('a failing link write prints the orphaned config, counts as failed and the next template is still exported', function (): void {
    hraMakeAvailable();
    $org = Organization::factory()->create();
    hreTemplate($org, 'tavus', ['name' => 'first']);
    hreTemplate($org, 'tavus', ['name' => 'second']);
    Http::fake(['*' => Http::sequence()->push(['id' => 'avc_ONE', 'sync_status' => 'synced'], 201)->push(['id' => 'avc_TWO', 'sync_status' => 'synced'], 201)]);
    $thrown = false;
    DB::beforeExecuting(function (string $query) use (&$thrown): void {
        if (! $thrown && str_starts_with($query, 'update "avatar_templates"')) {
            $thrown = true;

            throw new RuntimeException('connection lost: secret-sql-detail');
        }
    });

    [$exit, $output] = hreRun(true);

    expect($exit)->toBe(1)
        ->and($output)->toContain('name="first" failed link_write_failed orphaned_config=avc_ONE')
        ->and($output)->toContain('created avc_TWO')
        ->and($output)->not->toContain('secret-sql-detail');
    expect(array_column(hreRows(), 'hr_avatar_config_id'))->toBe([null, 'avc_TWO']);
});
