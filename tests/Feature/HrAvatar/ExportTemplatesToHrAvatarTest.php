<?php

declare(strict_types=1);

/**
 * `hr-avatar:export-templates` (hr-avatar-adapter A5): dry run by default; `--apply` creates one HR-Avatar
 * config per eligible template and writes ONLY `avatar_templates.hr_avatar_config_id`.
 */

use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
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
    expect($bound['config'])->toEqual(['avatarId' => 'a1', 'voiceId' => 'v1', 'voiceSpeed' => 1.1])
        ->and($bound['voice'])->toBe(['engine' => 'elevenlabs', 'voice_id' => 'ext_1'])
        ->and($plain['config'])->toEqual(['faceId' => 'r1', 'palId' => 'p1'])
        ->and($plain)->not->toHaveKey('voice');
    $sent = json_encode(hreBodies(), JSON_THROW_ON_ERROR);
    expect($sent)->not->toContain('SENTINEL')->not->toContain('Zed-DISPLAY-NAME')->not->toContain('persona')
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
