<?php

declare(strict_types=1);

/**
 * No mail is ever sent to the address a reusable-link visitor typed
 * (reusable-link-visitor-identity, api-3).
 *
 * The address is self-declared and unverified: mailing it on an operator's
 * re-issue would make BEAI write to an address whoever held the link chose to
 * type. The suppression sits at the DISPATCH site, because the invitation job
 * holds scalars only and reads no row by design. A visitor row is recognised by
 * the reusable-link marker of the row the request's `candidate_ref` names, never
 * by the address and never by the spelling of the reference.
 *
 * The redemption itself never queues anything, and the legacy placeholder rows
 * stay refused by the job's own guard.
 *
 * REQ: No Mail Is Ever Sent To A Visitor Address
 *      (sdd/reusable-link-visitor-identity/spec/reusable-interview-links)
 */

use App\Jobs\SendCandidateInvitationJob;
use App\Models\Participant;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\ReusableLinkFixtures as Fx;

beforeEach(function (): void {
    Fx::configureOrigin();
    config([
        'reusable_links.redeem.per_ip_per_minute' => 1000,
        'reusable_links.redeem.per_link_per_hour' => 1000,
    ]);
});

/**
 * A world with a redeemed visitor and an admin of its organisation.
 *
 * @return array{world: array<string, mixed>, visitor: Participant, admin: string}
 */
function visitorInvitationWorld(): array
{
    $world = Fx::redeemable();
    test()->postJson(Fx::REDEEM_URL, Fx::redeemBody($world['token'], Fx::identity('ada@example.test', 'Ada Lovelace')))->assertOk();
    $visitor = Fx::visitorsOf($world['link'])[0];
    $admin = authTokenForRole($world['org'], 'admin');
    resetAuthGuardState();

    return ['world' => $world, 'visitor' => $visitor, 'admin' => $admin];
}

/**
 * The body of an operator re-issue for `$candidateRef`.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function visitorInvitationReissue(int $projectId, string $candidateRef, string $email, array $extra = []): array
{
    return ['project_id' => $projectId, 'candidate_ref' => $candidateRef, 'display_name' => 'Ada Lovelace', 'email' => $email, ...$extra];
}

// ─── The operator re-issue ───────────────────────────────────────────────────

test('re-issuing a link for a visitor queues no invitation and reports email_sent false, with or without send_email', function (array $extra): void {
    Queue::fake();
    ['world' => $world, 'visitor' => $visitor, 'admin' => $admin] = visitorInvitationWorld();

    $response = $this->withToken($admin)->postJson('/api/entry-links', visitorInvitationReissue($world['project']->id, $visitor->candidate_ref, $visitor->email, $extra));

    $response->assertCreated();
    expect($response->json('email_sent'))->toBeFalse()
        ->and($response->json('entry_url'))->toBeString()->not->toBeEmpty();
    Queue::assertNotPushed(SendCandidateInvitationJob::class);
})->with([
    'send_email omitted' => [[]],
    'send_email true' => [['send_email' => true]],
]);

test('an ordinary candidate is still invited by default and email_sent says so', function (): void {
    Queue::fake();
    $world = Fx::redeemable();
    $admin = authTokenForRole($world['org'], 'admin');

    $response = $this->withToken($admin)->postJson('/api/entry-links', visitorInvitationReissue($world['project']->id, 'ordinary-1', 'grace@example.test'));

    $response->assertCreated();
    expect($response->json('email_sent'))->toBeTrue();
    Queue::assertPushed(SendCandidateInvitationJob::class, 1);
});

test('only the candidate_ref axis marks a visitor: a request whose email alone matches a visitor is invited normally', function (): void {
    Queue::fake();
    ['world' => $world, 'visitor' => $visitor, 'admin' => $admin] = visitorInvitationWorld();

    $response = $this->withToken($admin)->postJson('/api/entry-links', visitorInvitationReissue($world['project']->id, 'someone-else-1', $visitor->email));

    $response->assertCreated();
    expect($response->json('email_sent'))->toBeTrue();
    Queue::assertPushed(SendCandidateInvitationJob::class, 1);
});

test('a manual candidate_ref that merely starts with rlv_ is not a visitor', function (): void {
    Queue::fake();
    $world = Fx::redeemable();
    $admin = authTokenForRole($world['org'], 'admin');
    TenantContextScope::runFor($world['project']->organization_id, fn () => Participant::factory()
        ->forProject($world['project'])
        ->create(['candidate_ref' => 'rlv_manual-reference', 'email' => 'manual@example.test']));

    $response = $this->withToken($admin)->postJson('/api/entry-links', visitorInvitationReissue($world['project']->id, 'rlv_manual-reference', 'manual@example.test'));

    $response->assertCreated();
    expect($response->json('email_sent'))->toBeTrue();
    Queue::assertPushed(SendCandidateInvitationJob::class, 1);
});

test('a legacy placeholder row is still dispatched to the job, which refuses it itself', function (): void {
    Queue::fake();
    $world = Fx::redeemable();
    $admin = authTokenForRole($world['org'], 'admin');
    $legacyRef = 'rlv_01JABCDEFGHJKMNPQRSTVWXYZ0';
    TenantContextScope::runFor($world['project']->organization_id, fn () => Participant::factory()
        ->forProject($world['project'])
        ->create(['candidate_ref' => $legacyRef, 'email' => $legacyRef.'@invalid.beai.local']));

    $this->withToken($admin)->postJson('/api/entry-links', visitorInvitationReissue($world['project']->id, $legacyRef, $legacyRef.'@invalid.beai.local'))
        ->assertCreated();

    // No marker on a legacy row: the dispatch site cannot tell, and does not
    // need to. The job's own placeholder guard (CandidateInvitationTest) is what
    // keeps the mail from going out.
    Queue::assertPushed(SendCandidateInvitationJob::class, 1);
});

// ─── The redemption queues nothing ───────────────────────────────────────────

test('a redemption queues no job, no notification and no mail, whatever it ends in', function (string $outcome): void {
    Queue::fake();
    Notification::fake();
    Mail::fake();
    $world = Fx::redeemable();
    $identity = Fx::identity('ada@example.test', 'Ada Lovelace');

    if ($outcome === '409') {
        TenantContextScope::runFor($world['project']->organization_id, fn () => Participant::factory()
            ->forProject($world['project'])
            ->create(['email' => 'ada@example.test']));
    }

    $response = $this->postJson(Fx::REDEEM_URL, $outcome === '422' ? ['link_token' => $world['token']] : Fx::redeemBody($world['token'], $identity));

    $response->assertStatus((int) $outcome);
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
})->with(['200', '409', '422']);
