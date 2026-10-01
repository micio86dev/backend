<?php

declare(strict_types=1);

/**
 * Redemption under real concurrency (reusable-interview-links, B3b.8; design
 * AD-9, AD-12).
 *
 * A reusable link is redeemed by many people at once (a fair stand, a kiosk, an
 * open day) and can be disabled at any moment, so two guarantees have to hold
 * between real processes and not only in a single-threaded test:
 *
 *   - concurrent redemptions are serialised on the link row: N of them make N
 *     distinct visitors, the counter ends at N (no lost update) and the display
 *     numbers #1..#N are each used once;
 *   - a redemption racing a disable either completes before the disable commits
 *     or creates nothing: a visitor is NEVER created after `disabled_at`.
 *
 * Each request runs in a process of its own with its own database session (see
 * `ReusableLinkConcurrency`), against a committed throwaway database, and the
 * interleavings that matter are made to happen by holding the row lock in the
 * test and releasing it only once Postgres reports the requests queued behind
 * it. The ungated tests at the end simply let the processes race.
 *
 * Earlier concurrency tests in this suite (`C6/ConcurrentUpsertTest`) say that
 * true race coverage "needs process-level parallelism" and settle for sequential
 * idempotency. The catalogue concurrency tests are the precedent for doing it
 * for real; this is the same technique applied to the whole request.
 *
 * REQ: Redemption Is Atomic, Serialized And Race-Safe With Disable
 *      (sdd/reusable-interview-links/spec/reusable-interview-links-redemption)
 */

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Services\ReusableLinkTokenGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Carbon;
use Tests\Helpers\ReusableLinkConcurrency as Concurrent;
use Tests\Helpers\ReusableLinkFixtures as Fx;

afterEach(function (): void {
    Concurrent::close();
});

/**
 * A redemption request as an actor process sends it.
 *
 * Every actor carries a DISTINCT identity derived from `$n` unless one is
 * handed in: the identity helper's counter is per PHP process, and the actor
 * runs in its own process, so the default would repeat across actors.
 *
 * @param  array{display_name: string, email: string}|null  $identity
 * @return array{method: string, uri: string, ip: string, body: array<string, mixed>}
 */
function concurrencyRedeem(string $token, int $n, ?array $identity = null): array
{
    return [
        'method' => 'POST',
        'uri' => Fx::REDEEM_URL,
        'ip' => '10.30.0.'.$n,
        'body' => Fx::redeemBody($token, $identity ?? Fx::identity('actor-'.$n.'@example.test', 'Actor '.$n)),
    ];
}

/**
 * A Disable request, as an admin of the link's organisation sends it.
 *
 * @param  array{org: Organization, project: Project}  $world
 * @return array{method: string, uri: string, ip: string, headers: array<string, string>}
 */
function concurrencyDisable(array $world, ReusableInterviewLink $link, string $adminJwt): array
{
    return [
        'method' => 'DELETE',
        'uri' => '/api/projects/'.$world['project']->id.'/reusable-links/'.PublicId::encode($link),
        'ip' => '10.40.0.1',
        'headers' => ['Authorization' => 'Bearer '.$adminJwt],
    ];
}

/**
 * The link row as it is now, read past the tenant scope.
 */
function concurrencyLink(ReusableInterviewLink $link): ReusableInterviewLink
{
    return ReusableInterviewLink::withoutGlobalScopes()->findOrFail($link->id);
}

/**
 * Assert what `$count` successful redemptions of one link leave behind.
 *
 * @param  list<array{status: int, body: string}>  $results
 */
function concurrencyAssertRedeemed(ReusableInterviewLink $link, array $results, int $count): void
{
    foreach ($results as $result) {
        expect($result['status'])->toBe(200, $result['body']);
    }

    $tokens = array_map(fn (array $result): string => (string) json_decode($result['body'], true)['access_token'], $results);
    $visitors = Fx::visitorsOf($link);
    $names = array_map(fn ($visitor): string => $visitor->display_name, $visitors);
    sort($names, SORT_NATURAL);
    $expected = array_map(fn (int $n): string => 'Actor '.$n, range(1, $count));
    sort($expected, SORT_NATURAL);
    $emails = array_map(fn ($visitor): string => $visitor->email, $visitors);
    sort($emails, SORT_NATURAL);
    $expectedEmails = array_map(fn (int $n): string => 'actor-'.$n.'@example.test', range(1, $count));
    sort($expectedEmails, SORT_NATURAL);

    expect($visitors)->toHaveCount($count)
        ->and(array_unique(array_map(fn ($visitor): string => $visitor->candidate_ref, $visitors)))->toHaveCount($count)
        ->and(array_unique(array_map(fn ($visitor): string => $visitor->email, $visitors)))->toHaveCount($count)
        ->and($names)->toBe($expected)
        ->and($emails)->toBe($expectedEmails)
        ->and(array_unique($tokens))->toHaveCount($count)
        ->and(concurrencyLink($link)->uses_count)->toBe($count)
        ->and(concurrencyLink($link)->last_used_at)->not->toBeNull();
}

// ─── Serialised redemptions ──────────────────────────────────────────────────

test('ten redemptions queued behind the link row lock make ten distinct visitors and count exactly ten', function (): void {
    Concurrent::open();
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    // Something is mid-way through its own redemption and holds the row. Ten
    // real requests arrive meanwhile and all pass the pre-read, then queue.
    $holder = Concurrent::holdLinkLock($link->id);
    $actors = array_map(fn (int $n): int => Concurrent::startRequest(concurrencyRedeem($token, $n)), range(1, 10));

    Concurrent::waitForBlocked(10);
    expect(Fx::visitorsOf($link))->toBe([]);

    $holder->commit();
    $results = array_map(fn (int $actor): array => Concurrent::result($actor), $actors);

    concurrencyAssertRedeemed($link, $results, 10);
});

test('ten simultaneous redemptions, with nothing holding them back, make ten distinct visitors and count exactly ten', function (): void {
    Concurrent::open();
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    $actors = array_map(fn (int $n): int => Concurrent::startRequest(concurrencyRedeem($token, $n)), range(1, 10));
    $results = array_map(fn (int $actor): array => Concurrent::result($actor), $actors);

    concurrencyAssertRedeemed($link, $results, 10);
});

// ─── Racing a disable ────────────────────────────────────────────────────────

test('a disable that commits while a redemption waits for the row wins: the redemption is the generic 404 and creates nothing', function (): void {
    Concurrent::open();
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    // A Disable has written `disabled_at` and not committed. The redemption's
    // pre-read still sees an enabled link (it cannot see uncommitted work), so it
    // goes on to the locked section and waits there.
    $holder = Concurrent::holdUncommittedDisable($link->id);
    $actor = Concurrent::startRequest(concurrencyRedeem($token, 1));

    Concurrent::waitForBlocked(1);
    expect(Concurrent::isRunning($actor))->toBeTrue();

    $holder->commit();
    $result = Concurrent::result($actor);

    expect($result['status'])->toBe(404)
        ->and($result['body'])->toBe(Fx::NOT_FOUND_BODY)
        ->and(Fx::visitorsOf($link))->toBe([])
        ->and(concurrencyLink($link)->uses_count)->toBe(0)
        ->and(concurrencyLink($link)->last_used_at)->toBeNull()
        ->and(concurrencyLink($link)->disabled_at)->not->toBeNull();
});

test('a redemption and a disable contending for the row end consistently: the visitor was created before disabled_at, or it was refused', function (): void {
    Concurrent::open();
    $world = Fx::redeemable();
    ['link' => $link, 'token' => $token] = $world;
    $admin = authTokenForRole($world['org'], 'admin');

    // Both real requests reach the lock while it is held, one after the other,
    // and are released together.
    $holder = Concurrent::holdLinkLock($link->id);
    $redeem = Concurrent::startRequest(concurrencyRedeem($token, 1));
    Concurrent::waitForBlocked(1);
    $disable = Concurrent::startRequest(concurrencyDisable($world, $link, $admin));
    Concurrent::waitForBlocked(2);

    $holder->commit();
    $redeemResult = Concurrent::result($redeem);
    $disableResult = Concurrent::result($disable);

    $row = concurrencyLink($link);
    $visitors = Fx::visitorsOf($link);

    // The disable always takes effect...
    expect($disableResult['status'])->toBe(204)
        ->and($row->disabled_at)->not->toBeNull();

    // ...and the redemption either completed first or created nothing.
    if ($redeemResult['status'] === 200) {
        expect($visitors)->toHaveCount(1)
            ->and($row->uses_count)->toBe(1)
            ->and($visitors[0]->created_at->lessThanOrEqualTo($row->disabled_at))->toBeTrue();
    } else {
        expect($redeemResult['status'])->toBe(404)
            ->and($visitors)->toBe([])
            ->and($row->uses_count)->toBe(0);
    }
});

test('two disables queued behind the row lock write one audit row and keep the first disabled_at', function (): void {
    Concurrent::open();
    $world = Fx::redeemable();
    ['link' => $link] = $world;
    $admin = authTokenForRole($world['org'], 'admin');

    // Disable is idempotent and audited ONCE. Two operators pressing it at the
    // same moment must not write two audit rows or move the timestamp: the second
    // one has to find the link already disabled, which only the lock guarantees.
    $holder = Concurrent::holdLinkLock($link->id);
    $first = Concurrent::startRequest(concurrencyDisable($world, $link, $admin));
    $second = Concurrent::startRequest(concurrencyDisable($world, $link, $admin));
    Concurrent::waitForBlocked(2);

    $holder->commit();
    $results = [Concurrent::result($first), Concurrent::result($second)];

    $audit = AuditLog::withoutGlobalScopes()->where('action', 'reusable_link.disabled')->get();

    expect($results[0]['status'])->toBe(204)
        ->and($results[1]['status'])->toBe(204)
        ->and($audit)->toHaveCount(1)
        ->and(concurrencyLink($link)->disabled_at)->not->toBeNull()
        ->and(Carbon::parse($audit[0]->after['disabled_at'])->equalTo(concurrencyLink($link)->disabled_at))->toBeTrue();
});

test('redemptions and disables racing freely never leave a visitor created after the disable', function (): void {
    Concurrent::open();
    $world = Fx::redeemable();
    $admin = authTokenForRole($world['org'], 'admin');

    // Six links of one project, each with a redemption and a disable fired at it
    // at the same moment: twelve processes racing.
    $links = [];
    foreach (range(1, 6) as $n) {
        $token = ReusableLinkTokenGenerator::generate();
        $links[$n] = [
            'token' => $token,
            'link' => Fx::link($world['project'], [
                'token_hash' => ReusableLinkTokenGenerator::hash($token),
                'token_prefix' => ReusableLinkTokenGenerator::prefixOf($token),
            ]),
        ];
    }

    $actors = [];
    foreach ($links as $n => $entry) {
        $actors[$n] = [
            'redeem' => Concurrent::startRequest(concurrencyRedeem($entry['token'], $n)),
            'disable' => Concurrent::startRequest(concurrencyDisable($world, $entry['link'], $admin)),
        ];
    }

    foreach ($links as $n => $entry) {
        $redeem = Concurrent::result($actors[$n]['redeem']);
        $disable = Concurrent::result($actors[$n]['disable']);
        $row = concurrencyLink($entry['link']);
        $visitors = Fx::visitorsOf($entry['link']);

        expect($disable['status'])->toBe(204, "link {$n}")
            ->and($row->disabled_at)->not->toBeNull()
            ->and($visitors)->toHaveCount($row->uses_count);

        if ($redeem['status'] === 200) {
            expect($visitors)->toHaveCount(1, "link {$n}")
                ->and($visitors[0]->created_at->lessThanOrEqualTo($row->disabled_at))->toBeTrue("link {$n}: a visitor was created after the disable committed");
        } else {
            expect($redeem['status'])->toBe(404, "link {$n}")
                ->and($visitors)->toBe([], "link {$n}");
        }
    }
});

test('a disable that commits while a redemption of an already enrolled email waits for the row is a 404, never a 409', function (): void {
    Concurrent::open();
    ['project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable();
    TenantContextScope::runFor($project->organization_id, fn () => Participant::factory()
        ->forProject($project)
        ->create(['email' => 'ada@example.test']));

    // The disabled re-check outranks the duplicate check: a committed Disable
    // must answer the generic 404 even for an address that is already taken.
    $holder = Concurrent::holdUncommittedDisable($link->id);
    $actor = Concurrent::startRequest(concurrencyRedeem($token, 1, Fx::identity('ada@example.test')));
    Concurrent::waitForBlocked(1);

    $holder->commit();
    $result = Concurrent::result($actor);

    expect($result['status'])->toBe(404)
        ->and($result['body'])->toBe(Fx::NOT_FOUND_BODY);
});

// ─── The same address, raced ─────────────────────────────────────────────────

/**
 * A scheduled enrolment as an operator creates it (`POST /api/entry-links` with a
 * start time): the one admin path that writes the participant row eagerly.
 *
 * @param  array{org: Organization, project: Project}  $world
 * @return array{method: string, uri: string, ip: string, headers: array<string, string>, body: array<string, mixed>}
 */
function concurrencyOperatorEnrol(array $world, string $adminJwt, string $email): array
{
    return [
        'method' => 'POST',
        'uri' => '/api/entry-links',
        'ip' => '10.50.0.1',
        'headers' => ['Authorization' => 'Bearer '.$adminJwt],
        'body' => [
            'project_id' => $world['project']->id,
            'candidate_ref' => 'operator-enrolled-1',
            'display_name' => 'Operator Enrolled',
            'email' => $email,
            'lang' => 'en',
            'scheduled_at' => now('UTC')->addMinutes(30)->toIso8601String(),
        ],
    ];
}

test('two redemptions of one link with one email, queued behind the row lock, make one visitor and one 409', function (): void {
    Concurrent::open();
    ['link' => $link, 'token' => $token] = Fx::redeemable();
    $identity = Fx::identity('ada@example.test', 'Ada Lovelace');

    $holder = Concurrent::holdLinkLock($link->id);
    $actors = [
        Concurrent::startRequest(concurrencyRedeem($token, 1, $identity)),
        Concurrent::startRequest(concurrencyRedeem($token, 2, $identity)),
    ];
    Concurrent::waitForBlocked(2);

    $holder->commit();
    $results = array_map(fn (int $actor): array => Concurrent::result($actor), $actors);
    $statuses = array_column($results, 'status');
    sort($statuses);

    expect($statuses)->toBe([200, 409])
        ->and(Fx::visitorsOf($link))->toHaveCount(1)
        ->and(concurrencyLink($link)->uses_count)->toBe(1);
});

test('two redemptions of two links of one project with one email, released together, make one visitor and one 409', function (): void {
    Concurrent::open();
    $world = Fx::redeemable();
    ['project' => $project, 'link' => $first, 'token' => $firstToken] = $world;
    $secondToken = ReusableLinkTokenGenerator::generate();
    $second = Fx::link($project, [
        'token_hash' => ReusableLinkTokenGenerator::hash($secondToken),
        'token_prefix' => ReusableLinkTokenGenerator::prefixOf($secondToken),
    ]);
    $identity = Fx::identity('ada@example.test', 'Ada Lovelace');

    // The link-row lock cannot serialise two DIFFERENT links: both requests pass
    // the duplicate check on an empty table, and only the unique index decides.
    $holder = Concurrent::holdLinkLocks([$first->id, $second->id]);
    $actors = [
        Concurrent::startRequest(concurrencyRedeem($firstToken, 1, $identity)),
        Concurrent::startRequest(concurrencyRedeem($secondToken, 2, $identity)),
    ];
    Concurrent::waitForBlocked(2);

    $holder->commit();
    $results = array_map(fn (int $actor): array => Concurrent::result($actor), $actors);
    $statuses = array_column($results, 'status');
    sort($statuses);

    expect($statuses)->toBe([200, 409])
        ->and(count(Fx::visitorsOf($first)) + count(Fx::visitorsOf($second)))->toBe(1)
        ->and(concurrencyLink($first)->uses_count + concurrencyLink($second)->uses_count)->toBe(1);

    $loser = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 409))[0];
    expect($loser['body'])->toBe('{"message":"duplicate_enrolment"}');
});

test('two redemptions of two links with case-variant spellings of one address, released together, still make one visitor and one 409', function (): void {
    Concurrent::open();
    ['project' => $project, 'link' => $first, 'token' => $firstToken] = Fx::redeemable();
    $secondToken = ReusableLinkTokenGenerator::generate();
    $second = Fx::link($project, [
        'token_hash' => ReusableLinkTokenGenerator::hash($secondToken),
        'token_prefix' => ReusableLinkTokenGenerator::prefixOf($secondToken),
    ]);

    // Both spellings are normalised to one lower-case address before any write,
    // so the case-sensitive unique index sees the same value and decides.
    $holder = Concurrent::holdLinkLocks([$first->id, $second->id]);
    $actors = [
        Concurrent::startRequest(concurrencyRedeem($firstToken, 1, Fx::identity('Ada@Example.test', 'Ada Lovelace'))),
        Concurrent::startRequest(concurrencyRedeem($secondToken, 2, Fx::identity('ada@EXAMPLE.TEST', 'Ada Lovelace'))),
    ];
    Concurrent::waitForBlocked(2);

    $holder->commit();
    $statuses = array_map(fn (int $actor): int => Concurrent::result($actor)['status'], $actors);
    sort($statuses);

    expect($statuses)->toBe([200, 409])
        ->and(count(Fx::visitorsOf($first)) + count(Fx::visitorsOf($second)))->toBe(1)
        ->and(Participant::query()->where('project_id', $project->id)->pluck('email')->all())->toBe(['ada@example.test']);
});

test('an operator enrolment committed while a redemption waits for the row lock makes the redemption a 409, never a 500', function (): void {
    Concurrent::open();
    $world = Fx::redeemable();
    ['link' => $link, 'token' => $token] = $world;
    $admin = authTokenForRole($world['org'], 'admin');

    // The redemption is already past its checks and queued on the link row; the
    // operator's enrolment is not on that row, so it commits first. The
    // redemption then wakes up and must find the address taken.
    $holder = Concurrent::holdLinkLock($link->id);
    $redeem = Concurrent::startRequest(concurrencyRedeem($token, 1, Fx::identity('ada@example.test', 'Ada Lovelace')));
    Concurrent::waitForBlocked(1);

    $operator = Concurrent::result(Concurrent::startRequest(concurrencyOperatorEnrol($world, $admin, 'ada@example.test')));
    $holder->commit();
    $result = Concurrent::result($redeem);

    expect($operator['status'])->toBe(201)
        ->and($result['status'])->toBe(409)
        ->and($result['body'])->toBe('{"message":"duplicate_enrolment"}')
        ->and(Participant::query()->where('project_id', $world['project']->id)->where('email', 'ada@example.test')->count())->toBe(1)
        ->and(Fx::visitorsOf($link))->toBe([])
        ->and(concurrencyLink($link)->uses_count)->toBe(0);
});

test('an operator enrolment and a redemption racing freely for one email leave exactly one participant and never a 500', function (): void {
    Concurrent::open();
    $world = Fx::redeemable();
    ['link' => $link, 'token' => $token] = $world;
    $admin = authTokenForRole($world['org'], 'admin');

    $actors = [
        'redeem' => Concurrent::startRequest(concurrencyRedeem($token, 1, Fx::identity('ada@example.test', 'Ada Lovelace'))),
        'operator' => Concurrent::startRequest(concurrencyOperatorEnrol($world, $admin, 'ada@example.test')),
    ];
    $redeem = Concurrent::result($actors['redeem']);
    $operator = Concurrent::result($actors['operator']);

    // Exactly one of them won, in either order; the loser is a refusal, not a 500.
    expect([$redeem['status'], $operator['status']])->toBeIn([[200, 409], [409, 201]])
        ->and(Participant::query()->where('project_id', $world['project']->id)->where('email', 'ada@example.test')->count())->toBe(1)
        ->and(concurrencyLink($link)->uses_count)->toBe($redeem['status'] === 200 ? 1 : 0);
});
