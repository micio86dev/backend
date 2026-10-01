<?php

declare(strict_types=1);

/**
 * The single-use sso-link mechanism is unchanged by reusable links
 * (reusable-interview-links, B3b.3).
 *
 * Reusable links are a SECOND way in, with their own door and their own table.
 * Everything the first way promised has to survive them, and none of it is
 * asserted by the reusable-link suites, which look at the new feature. These pins
 * look at the OLD one, in the presence of the new one:
 *
 *   - the 30-minute lifetime, the single-use jti, and the interviewability gate
 *     that does not burn it;
 *   - a redemption never touches the single-use jti cache (it has no jti);
 *   - an sso-link minted earlier still exchanges after a reusable link is
 *     created on its project, and after that link is disabled, and minting an
 *     sso-link never disables a reusable link;
 *   - re-issuing an entry link to a visitor keeps the visitor's origin marker;
 *   - `POST /api/entry-links` answers exactly what it answered before.
 *
 * Every test here passes without any change to production code: they are
 * regression pins, written to fail if a later change breaks the old behaviour.
 *
 * REQ: Single-Use Entry Links Are Unchanged (Regression Pins)
 *      (sdd/reusable-interview-links/spec/reusable-interview-links-redemption)
 */

use App\Models\Participant;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Event;
use Tests\Helpers\ReusableLinkFixtures as Fx;

beforeEach(function (): void {
    Fx::configureOrigin();
    config([
        'reusable_links.redeem.per_ip_per_minute' => 100000,
        'reusable_links.redeem.per_link_per_hour' => 100000,
    ]);
});

/**
 * Mint an sso-link JWT for a new candidate of `$project`.
 */
function ssoPinMint(Project $project, ?string $candidateRef = null): string
{
    return CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => $candidateRef ?? 'sso-'.uniqid(),
        'display_name' => 'Ada Lovelace',
        'email' => uniqid('ada-').'@example.test',
        'project_id' => $project->id,
        'org_id' => $project->organization_id,
        'role_code' => $project->role_code,
        'lang' => 'en',
    ]);
}

/**
 * The claims of a JWT, read straight from its payload segment.
 *
 * @return array<string, mixed>
 */
function ssoPinClaims(string $jwt): array
{
    return json_decode((string) base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Run `$action` and return every `sso_jti` cache key it read, wrote or forgot.
 *
 * @param  Closure(): mixed  $action
 * @return list<string>
 */
function ssoPinJtiKeys(Closure $action): array
{
    $keys = [];
    Event::listen([CacheHit::class, CacheMissed::class, KeyWritten::class, KeyForgotten::class], function (object $event) use (&$keys): void {
        $keys[] = $event->key;
    });

    $action();

    return array_values(array_filter($keys, fn (string $key): bool => str_contains($key, 'sso_jti')));
}

// ─── Lifetime and single use ─────────────────────────────────────────────────

test('an sso-link still lives 30 minutes', function (): void {
    ['project' => $project] = Fx::redeemable();

    $claims = ssoPinClaims(ssoPinMint($project));

    expect(CandidateTokenFactory::SSO_LINK_TTL_MINUTES)->toBe(30)
        ->and($claims['exp'] - $claims['iat'])->toBe(1800)
        ->and($claims['typ'])->toBe('sso-link');
});

test('an sso-link is still single-use, on a project that also has a reusable link', function (): void {
    ['project' => $project] = Fx::redeemable();
    $token = ssoPinMint($project);

    $this->getJson('/api/sso/exchange?token='.$token)->assertOk();
    $this->getJson('/api/sso/exchange?token='.$token)->assertUnauthorized();
});

test('an interviewability refusal still does not burn the sso-link, on a project that also has a reusable link', function (): void {
    ['project' => $project] = Fx::redeemable(interviewable: false);
    $token = ssoPinMint($project);

    $this->getJson('/api/sso/exchange?token='.$token)->assertForbidden();

    // The project is fixed; the SAME token, never spent, now opens it.
    TenantContextScope::runFor($project->organization_id, fn () => makeProjectInterviewable($project));

    $this->getJson('/api/sso/exchange?token='.$token)->assertOk();
});

test('a redemption reads and writes no sso_jti key, while a real exchange does', function (): void {
    ['link' => $link, 'project' => $project, 'token' => $linkToken] = Fx::redeemable();

    // The control: the listener sees the single-use key of an exchange, so an
    // empty result below means "not touched", not "not listening".
    $exchange = ssoPinJtiKeys(fn () => $this->getJson('/api/sso/exchange?token='.ssoPinMint($project))->assertOk());
    $redemption = ssoPinJtiKeys(fn () => $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($linkToken))->assertOk());

    expect($exchange)->not->toBe([])
        ->and($redemption)->toBe([])
        ->and(Fx::visitorsOf($link))->toHaveCount(1);
});

// ─── Coexistence ─────────────────────────────────────────────────────────────

test('an unexpired, unexchanged sso-link still exchanges after a reusable link is created on its project and after it is disabled', function (): void {
    ['org' => $org, 'project' => $project] = Fx::redeemable();
    $ssoLink = ssoPinMint($project, 'sso-survivor');
    $admin = authTokenForRole($org, 'admin');

    $created = $this->withToken($admin)->postJson("/api/projects/{$project->id}/reusable-links", ['label' => 'Stand'])
        ->assertCreated();
    $linkId = (string) $created->json('data.id');

    $this->withToken($admin)->deleteJson("/api/projects/{$project->id}/reusable-links/{$linkId}")->assertNoContent();

    resetAuthGuardState();
    $this->flushHeaders()->getJson('/api/sso/exchange?token='.$ssoLink)->assertOk();

    expect(Participant::query()->where('project_id', $project->id)->where('candidate_ref', 'sso-survivor')->exists())->toBeTrue();
});

test('minting entry links never disables a reusable link', function (): void {
    ['org' => $org, 'project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable();
    $operator = authTokenForRole($org, 'operator');

    $this->withToken($operator)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'cand-1',
        'display_name' => 'Candidate One',
        'email' => uniqid('cand-').'@example.test',
        'send_email' => false,
    ])->assertCreated();

    expect(ReusableInterviewLink::withoutGlobalScopes()->findOrFail($link->id)->disabled_at)->toBeNull();

    resetAuthGuardState();
    $this->flushHeaders()->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();
});

test('re-issuing an entry link to a reusable-link visitor keeps the visitor and its origin marker', function (): void {
    ['org' => $org, 'project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable();
    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();
    $visitor = Fx::visitorsOf($link)[0];
    $operator = authTokenForRole($org, 'operator');

    // What the participant page's "Generate new link" sends: the row's own
    // identity (a visitor's email is a placeholder).
    $reissued = $this->withToken($operator)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => $visitor->candidate_ref,
        'display_name' => $visitor->display_name,
        'email' => $visitor->email,
        'send_email' => false,
    ])->assertCreated();

    $ssoLink = basename((string) parse_url((string) $reissued->json('entry_url'), PHP_URL_PATH));
    resetAuthGuardState();
    $this->flushHeaders()->getJson('/api/sso/exchange?token='.$ssoLink)->assertOk();

    $after = Participant::query()->where('project_id', $project->id)->get();
    expect($after)->toHaveCount(1)
        ->and($after[0]->id)->toBe($visitor->id)
        ->and($after[0]->candidate_ref)->toBe($visitor->candidate_ref)
        ->and($after[0]->reusable_interview_link_id)->toBe($link->id);
});

// ─── The operator entry-link response ────────────────────────────────────────

test('POST /api/entry-links answers exactly what it answered before reusable links existed', function (): void {
    ['org' => $org, 'project' => $project] = Fx::redeemable();
    $operator = authTokenForRole($org, 'operator');

    $response = $this->withToken($operator)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'cand-shape',
        'display_name' => 'Shape Candidate',
        'email' => uniqid('cand-').'@example.test',
        'lang' => 'en',
        'send_email' => false,
    ]);

    $response->assertCreated();
    $keys = array_keys($response->json());
    sort($keys);
    expect($keys)->toBe(['email_sent', 'entry_url', 'expires_at']);

    // Nothing of the new feature leaks into it, at any depth or in any value.
    $body = (string) $response->getContent();
    expect(Fx::keysAtAnyDepth($response->json()))->each->not->toContain('reusable')
        ->and($body)->not->toContain('reusable')
        ->and($body)->not->toContain('beai_rl_')
        ->and($body)->not->toContain('rlk_')
        ->and($body)->not->toContain('link_token');
});
