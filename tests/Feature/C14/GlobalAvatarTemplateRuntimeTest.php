<?php

declare(strict_types=1);

/**
 * Runtime reads resolve a platform (global) avatar template (A2).
 *
 * A project pinned to a global must reach the interview with the global's own
 * configuration on every read site, in every `is_active` state, and a global
 * must NEVER become an organization's unpinned default. The pinned read goes
 * through the named `availableToTenant()` scope: the strict tenant scope alone
 * cannot see a NULL-organization row, and the failure mode is silent (the
 * provider falls back to the environment defaults and the interview starts
 * with the wrong avatar and voice).
 */

use App\Models\AvatarTemplate;
use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLivePeriod;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ConversationLlm\InterviewSessionLlmSnapshot;
use App\Services\Provider\ProviderPreflight;
use App\Support\AvatarTemplates\ActiveTemplateResolver;
use App\Support\Interview\SessionLiveClock;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;

uses(RefreshDatabase::class);

/**
 * A project of `$org` pinned to `$template`, with the org left holding no
 * template of its own.
 *
 * @param  array<string, mixed>  $projectAttributes
 */
function grtPinnedProject(Organization $org, AvatarTemplate $template, array $projectAttributes = []): Project
{
    [$project] = casProject($org, 1);

    casInTenant($org, fn () => $project->forceFill(['avatar_template_id' => $template->id] + $projectAttributes)->save());
    DB::table('avatar_templates')->where('organization_id', $org->id)->delete();

    return $project->fresh();
}

/** @return array<string, mixed>|null the body of the single provider call whose URL contains `$needle` */
function grtProviderBody(string $needle): ?array
{
    foreach (Http::recorded() as [$request]) {
        if (str_contains($request->url(), $needle)) {
            return $request->data();
        }
    }

    return null;
}

/** @return array<string, mixed> */
function grtTavusFake(): array
{
    return [
        '*tavusapi*/v2/conversations*' => Http::response([
            'conversation_id' => 'conv-'.uniqid(),
            'conversation_url' => 'https://tavus.example/conv',
        ], 200),
    ];
}

function grtStart(mixed $test, Organization $org, Project $project): TestResponse
{
    $participant = casParticipant($org, $project, 'in_attesa');

    return $test->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start');
}

function grtSession(Organization $org, AvatarTemplate $pin, string $provider = 'heygen'): InterviewSession
{
    return TenantContextScope::runFor($org->id, function () use ($org, $pin, $provider): InterviewSession {
        $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'framework_version_id' => $fv->id,
            'avatar_template_id' => $pin->id,
        ]);
        $participant = Participant::factory()->create(['organization_id' => $org->id, 'project_id' => $project->id]);

        return InterviewSession::factory()->create([
            'organization_id' => $org->id,
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'framework_version_id' => $fv->id,
            'provider' => $provider,
        ]);
    });
}

// ─── Resolver ────────────────────────────────────────────────────────────────

test('a pinned global resolves in either is_active state', function (bool $active): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal(['is_active' => $active]);
    $project = grtPinnedProject($org, $global);

    $found = TenantContextScope::runFor($org->id, fn () => app(ActiveTemplateResolver::class)->resolve('heygen', $project->id));

    expect($found?->id)->toBe($global->id)->and($found->isPlatform())->toBeTrue();
})->with([true, false]);

test('an unresolvable pin returns null and logs avatar_template.pin_unresolved', function (string $case): void {
    Log::spy();
    $org = Organization::factory()->create();
    $other = Organization::factory()->create();
    $global = PlatformTemplates::insertGlobal();
    $project = grtPinnedProject($org, $global);

    $orphan = TenantContextScope::runFor(
        $case === 'foreign' ? $other->id : $org->id,
        fn (): AvatarTemplate => AvatarTemplate::create(['name' => 'Orphan', 'provider' => 'heygen', 'config' => []]),
    );

    if ($case === 'trashed') {
        $orphan->delete();
    }
    DB::table('projects')->where('id', $project->id)->update(['avatar_template_id' => $orphan->id]);

    $found = TenantContextScope::runFor($org->id, fn () => app(ActiveTemplateResolver::class)->resolve('heygen', $project->id));

    expect($found)->toBeNull();
    Log::shouldHaveReceived('warning')->once()->with('avatar_template.pin_unresolved', [
        'project_id' => $project->id,
        'template_id' => $orphan->id,
        'provider' => 'heygen',
    ]);
})->with(['trashed', 'foreign']);

test('a pinned template of another provider returns null WITHOUT a warning', function (): void {
    Log::spy();
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertActiveGlobal();
    $project = grtPinnedProject($org, $global);

    $found = TenantContextScope::runFor($org->id, fn () => app(ActiveTemplateResolver::class)->resolve('tavus', $project->id));

    expect($found)->toBeNull();
    Log::shouldNotHaveReceived('warning');
});

test('a global is never the unpinned default, and the organization own active template wins', function (): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertActiveGlobal();
    $resolver = fn (): ?AvatarTemplate => TenantContextScope::runFor($org->id, fn () => app(ActiveTemplateResolver::class)->resolve('heygen', null));

    expect($resolver())->toBeNull();

    $own = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Own', 'provider' => 'heygen', 'config' => [], 'is_active' => true,
    ]));

    expect($resolver()?->id)->toBe($own->id)->and($own->id)->not->toBe($global->id);
});

// ─── Interview start and resume ──────────────────────────────────────────────

test('HeyGen: a project pinned to a global starts with the global avatar and voice, not the environment defaults', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();
    config(['interview.heygen.avatar_id' => 'env_avatar', 'interview.heygen.voice_id' => 'env_voice']);

    $org = casOrg();
    $global = PlatformTemplates::insertActiveGlobal(['config' => ['avatarId' => 'av_global', 'voiceId' => 'vo_global']]);
    $project = grtPinnedProject($org, $global);

    grtStart($this, $org, $project)->assertStatus(201);

    $body = grtProviderBody('/sessions/token');
    expect($body['avatar_id'] ?? null)->toBe('av_global')
        ->and($body['avatar_persona']['voice_id'] ?? null)->toBe('vo_global');
});

test('Tavus: the global provider is derived from the pin and its face and PAL reach the provider', function (): void {
    Http::fake(grtTavusFake());
    Queue::fake();

    // No `provider_override` on purpose: `providerNameFor()` must derive Tavus
    // from the pinned global, or the start would go to the HeyGen default.
    $org = casOrg();
    $global = PlatformTemplates::insertActiveGlobal([
        'provider' => 'tavus',
        'config' => ['faceId' => 'f_global', 'palId' => 'p_global'],
    ]);
    $project = grtPinnedProject($org, $global);

    grtStart($this, $org, $project)->assertStatus(201);

    $body = grtProviderBody('/v2/conversations');
    expect($body['replica_id'] ?? null)->toBe('f_global')->and($body['persona_id'] ?? null)->toBe('p_global');
});

test('audio_only is read from the pinned global config', function (): void {
    Http::fake(grtTavusFake());
    Queue::fake();

    $org = casOrg();
    $global = PlatformTemplates::insertActiveGlobal([
        'provider' => 'tavus',
        'config' => ['faceId' => 'f_global', 'palId' => 'p_global', 'audioOnly' => true],
    ]);

    grtStart($this, $org, grtPinnedProject($org, $global))->assertStatus(201)->assertJsonPath('audio_only', true);
});

test('a retired global keeps serving its existing pin', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    $global = PlatformTemplates::insertGlobal(['config' => ['avatarId' => 'av_retired', 'voiceId' => 'vo_retired']]);

    grtStart($this, $org, grtPinnedProject($org, $global))->assertStatus(201);

    expect(grtProviderBody('/sessions/token')['avatar_id'] ?? null)->toBe('av_retired');
});

test('editing the global reaches the next resume of an in-progress session', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    $global = PlatformTemplates::insertActiveGlobal(['config' => ['avatarId' => 'av_global', 'voiceId' => 'vo_before']]);
    $project = grtPinnedProject($org, $global);
    $participant = casParticipant($org, $project, 'in_attesa');
    $headers = ['Authorization' => 'Bearer '.casBearer($participant)];

    $this->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(201);

    // What /suspend leaves behind: an in_corso session with no provider ref.
    DB::table('interview_sessions')->where('participant_id', $participant->id)->update(['provider_session_ref' => null]);
    DB::table('avatar_templates')->where('id', $global->id)
        ->update(['config' => json_encode(['avatarId' => 'av_global', 'voiceId' => 'vo_after'])]);
    Http::fake(heygenOkFake());

    $this->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(201);

    expect(grtProviderBody('/sessions/token')['avatar_persona']['voice_id'] ?? null)->toBe('vo_after');
});

test('another organization active template is never selected for a project pinned to a global', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    $other = casOrg();
    TenantContextScope::runFor($other->id, fn () => AvatarTemplate::create([
        'name' => 'Other active', 'provider' => 'heygen', 'is_active' => true,
        'config' => ['avatarId' => 'av_other', 'voiceId' => 'vo_other'],
    ]));
    $global = PlatformTemplates::insertActiveGlobal(['config' => ['avatarId' => 'av_global', 'voiceId' => 'vo_global']]);

    grtStart($this, $org, grtPinnedProject($org, $global))->assertStatus(201);

    expect(grtProviderBody('/sessions/token')['avatar_id'] ?? null)->toBe('av_global');
});

// ─── Sites that go through the resolver ──────────────────────────────────────

test('the LLM snapshot stamps the pinned global as the session template', function (): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertActiveGlobal();
    $session = grtSession($org, $global);

    TenantContextScope::runFor($org->id, fn () => app(InterviewSessionLlmSnapshot::class)->stamp($session, null));

    expect($session->avatar_template_id)->toBe($global->id);
});

test('pre-flight validates the pinned global config instead of the environment defaults', function (): void {
    config(['interview.heygen.api_key' => 'key', 'interview.heygen.avatar_id' => '', 'interview.heygen.voice_id' => '']);
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertActiveGlobal(['config' => ['avatarId' => 'av_global', 'voiceId' => 'vo_global']]);
    $project = grtPinnedProject($org, $global);

    $errors = TenantContextScope::runFor($org->id, fn () => app(ProviderPreflight::class)->check('heygen', $project->id));

    expect($errors)->toBe([]);
});

test('the live clock honours the ceiling configured on the pinned global', function (): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertActiveGlobal([
        'config' => ['avatarId' => 'av', 'voiceId' => 'vo', 'maxSessionDurationSec' => 300],
    ]);
    $session = grtSession($org, $global);

    TenantContextScope::runFor($org->id, function () use ($session): void {
        $clock = new SessionLiveClock;
        $clock->open($session, 'ref-1');
        $period = InterviewSessionLivePeriod::where('interview_session_id', $session->id)->firstOrFail();
        $period->started_at = now()->subHours(5);
        $period->save();
        $clock->close($session, 'resume');
    });

    $period = InterviewSessionLivePeriod::withoutGlobalScopes()->where('interview_session_id', $session->id)->firstOrFail();
    expect($period->ended_at->getTimestamp() - $period->started_at->getTimestamp())->toBe(300);
});

test('the project relation resolves a pinned global, lazily and eager-loaded', function (): void {
    $org = Organization::factory()->create();
    $global = PlatformTemplates::insertActiveGlobal();
    $project = grtPinnedProject($org, $global);

    [$lazy, $eager] = TenantContextScope::runFor($org->id, fn (): array => [
        Project::findOrFail($project->id)->avatarTemplate,
        Project::with('avatarTemplate')->findOrFail($project->id)->avatarTemplate,
    ]);

    expect($lazy?->id)->toBe($global->id)->and($eager?->id)->toBe($global->id);
});
