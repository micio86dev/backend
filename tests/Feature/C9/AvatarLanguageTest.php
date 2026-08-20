<?php

declare(strict_types=1);

/**
 * The avatar speaks the PROJECT's language (avatar-language-follows-project).
 *
 * Four things in an interview carry a language and they did not share a source.
 * An organization's active AvatarTemplate could override what the avatar SPOKE,
 * while the UI, the opening greeting and the closing phrases came from the
 * project or the participant — so an avatar could be configured for one language
 * and handed scripted lines in another.
 *
 * The template cannot be the source of truth: `avatar_templates` is scoped by
 * organization with no project_id, so one active template per organization would
 * have to serve every project in it, while `project.language` is per project.
 */

use App\Models\AvatarTemplate;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * The body of the ONE `/sessions/token` request, or null.
 *
 * Http::assertSent() passes when ANY recorded request satisfies its closure, so a
 * closure that returns true for the requests it does not care about asserts
 * nothing — the /contexts call alone makes it green. Pulling the single request
 * out and asserting on its body directly is the only shape that can fail.
 */
function langTokenBody(): ?array
{
    foreach (Http::recorded() as [$request, $response]) {
        if (str_contains($request->url(), '/sessions/token')) {
            return $request->data();
        }
    }

    return null;
}

function langActiveTemplate(int $orgId, string $provider, array $config): AvatarTemplate
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgId);
    $resolver->setBypass(false);

    $t = new AvatarTemplate;
    $t->forceFill([
        'organization_id' => $orgId,
        'name' => 'lang-'.uniqid(),
        'provider' => $provider,
        'config' => $config,
        'is_active' => true,
    ]);
    $t->save();

    return $t;
}

// ─── HeyGen ───────────────────────────────────────────────────────────────────

test('an active template CANNOT override the avatar spoken language', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 1);
    casInTenant($org, fn () => $project->forceFill(['language' => 'it'])->save());

    // A template that tries. It must not win.
    langActiveTemplate($org->id, 'heygen', ['avatarId' => 'tmpl-avatar', 'language' => 'en']);

    $participant = casParticipant($org, $project);

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    $body = langTokenBody();
    expect($body)->not->toBeNull('the session-token call must have been made');
    expect($body['avatar_persona']['language'] ?? null)->toBe('it');
});

test('a template still controls avatar identity — only the language is taken back', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 1);
    langActiveTemplate($org->id, 'heygen', ['avatarId' => 'tmpl-avatar-123', 'language' => 'en']);

    $participant = casParticipant($org, $project);

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    $body = langTokenBody();
    expect($body)->not->toBeNull();
    expect($body['avatar_id'] ?? null)->toBe('tmpl-avatar-123');
});

// ─── Closing phrases ──────────────────────────────────────────────────────────

test('the closing phrases come from the PROJECT, not the participant', function (): void {
    // interview-session/spec.md already REQUIRED the project language here; the
    // code read $participant->language, which arrives from unvalidated M2M input
    // — a caller can post "fr" on an "it" project.
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project] = casProject($org, 1);
    casInTenant($org, fn () => $project->forceFill(['language' => 'it'])->save());

    $participant = casParticipant($org, $project);
    casInTenant($org, fn () => $participant->forceFill(['language' => 'en'])->save());

    $response = $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant->fresh())])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    $expected = trans('interview.end_phrase', [], 'it');
    expect($response->json('question_context.end_phrase'))->toBe($expected);
});

// ─── Tenancy ──────────────────────────────────────────────────────────────────

test('one organization template never reaches another organization session', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $orgA = casOrg();
    [$projectA] = casProject($orgA, 1);
    casInTenant($orgA, fn () => $projectA->forceFill(['language' => 'it'])->save());
    langActiveTemplate($orgA->id, 'heygen', ['avatarId' => 'A-avatar', 'language' => 'en']);

    $orgB = casOrg();
    [$projectB] = casProject($orgB, 1);
    casInTenant($orgB, fn () => $projectB->forceFill(['language' => 'en'])->save());

    $participantB = casParticipant($orgB, $projectB);

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participantB)])
        ->postJson('/api/candidate/interview/start')->assertStatus(201);

    $body = langTokenBody();
    expect($body)->not->toBeNull();
    expect($body['avatar_id'] ?? null)->not->toBe('A-avatar');
    expect($body['avatar_persona']['language'] ?? null)->toBe('en');
});
