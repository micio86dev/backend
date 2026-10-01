<?php

declare(strict_types=1);

/**
 * A reusable link token, and the hash it is stored as, leave no trace
 * (reusable-interview-links, B3b.6).
 *
 * The raw token is a bearer secret that never expires. It exists in exactly two
 * places: the single 201 creation response (inside `entry_url`) and the URL
 * fragment the operator copies. Everywhere else a trace of it would be a
 * credential sitting in a place that is read by people and systems who were
 * never given the link: a log file, an error report, a job payload, a cache key,
 * an audit row, another endpoint's response, or any column of any table.
 *
 * These are observations of the running application, not of its source: logs and
 * reported exceptions are captured as they are written, queued jobs as they are
 * pushed, cache keys as they are used, and the database is searched row by row
 * after a whole create, redeem and disable lifecycle.
 *
 * REQ: The Raw Token And Its Hash Are Never Exposed Outside The Creation
 *      Response, Link Mutations Are Audited And Redemptions Are Not
 *      (sdd/reusable-interview-links/spec/reusable-interview-links-redemption)
 */

use App\Events\ParticipantCreated;
use App\Jobs\DeliverWebhookJob;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Services\ReusableLinkTokenGenerator;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Helpers\PublicApi\ThrowingJwtAuth;
use Tests\Helpers\ReusableLinkFixtures as Fx;
use Tymon\JWTAuth\JWTAuth;

beforeEach(function (): void {
    Fx::configureOrigin();
    Queue::fake();
    Http::fake();
    config([
        'reusable_links.redeem.per_ip_per_minute' => 100000,
        'reusable_links.redeem.per_link_per_hour' => 100000,
    ]);
});

/**
 * A string that holds everything a value can show of itself: exceptions as
 * their message and stack trace (which is what a log line carries), arrays with
 * their keys, objects by their properties.
 */
function neverLogsFlatten(mixed $value): string
{
    return match (true) {
        $value instanceof Throwable => (string) $value,
        is_array($value) => implode(' ', array_map(
            neverLogsFlatten(...),
            array_merge(array_map('strval', array_keys($value)), array_values($value)),
        )),
        is_object($value) => $value::class.' '.neverLogsFlatten(get_object_vars($value)),
        default => (string) json_encode($value),
    };
}

/**
 * Run `$action` and return every log record written and every exception
 * reported while it ran, each as one searchable string.
 *
 * @param  Closure(): mixed  $action
 * @return array{records: list<string>, reported: list<string>}
 */
function neverLogsCapture(Closure $action): array
{
    $records = [];
    $reported = [];

    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$records): void {
        $records[] = $event->level.' '.$event->message.' '.neverLogsFlatten($event->context);
    });
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported): void {
        $reported[] = (string) $e;
    });

    $action();

    return ['records' => $records, 'reported' => $reported];
}

/**
 * Assert that none of `$haystacks` shows the raw token, a 16-character window of
 * its random part (a stack trace truncates the arguments it prints, a half
 * copy is still a copy), or the hash.
 *
 * @param  list<string>  $haystacks
 */
function neverLogsAssertClean(array $haystacks, string $token, string $label): void
{
    $random = substr($token, strlen(ReusableLinkTokenGenerator::MARKER));
    $hash = ReusableLinkTokenGenerator::hash($token);

    // One needle per assertion: Pest's `not->toContain($a, $b)` is "not (a AND
    // b)", so a second argument would let the first one through.
    $needles = [$token, $hash];
    foreach (range(0, strlen($random) - 16) as $start) {
        $needles[] = substr($random, $start, 16);
    }

    foreach ($haystacks as $haystack) {
        foreach ($needles as $needle) {
            expect(str_contains($haystack, $needle))->toBeFalse("{$label}: the token, its hash or a copy of it is in: ".substr($haystack, 0, 200));
        }
    }
}

/**
 * The cache keys `$action` read, wrote or forgot.
 *
 * @param  Closure(): mixed  $action
 * @return list<string>
 */
function neverLogsCacheKeys(Closure $action): array
{
    $keys = [];
    Event::listen([CacheHit::class, CacheMissed::class, KeyWritten::class, KeyForgotten::class], function (object $event) use (&$keys): void {
        $keys[] = $event->key;
    });

    $action();

    return $keys;
}

/**
 * Create a link over the admin API and return what an operator holds.
 *
 * @param  array{org: Organization, project: Project}  $world
 * @return array{token: string, entryUrl: string, id: string, created: string, admin: string}
 */
function neverLogsCreateLink(array $world): array
{
    $admin = authTokenForRole($world['org'], 'admin');
    $response = test()->withToken($admin)
        ->postJson("/api/projects/{$world['project']->id}/reusable-links", ['label' => 'Stand'])
        ->assertCreated();

    resetAuthGuardState();

    return [
        'token' => Fx::tokenOf((string) $response->json('entry_url')),
        'entryUrl' => (string) $response->json('entry_url'),
        'id' => (string) $response->json('data.id'),
        'created' => (string) $response->getContent(),
        'admin' => $admin,
    ];
}

// ─── Logs and reported exceptions ────────────────────────────────────────────

test('no log record or reported exception shows the token or its hash, whatever the redemption does', function (string $scenario): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable(
        projectAttributes: $scenario === 'a closed project' ? ['status' => 'inactive'] : [],
        linkAttributes: $scenario === 'a disabled link' ? ['disabled_at' => now()] : [],
    );
    $unknown = ReusableLinkTokenGenerator::generate();

    $captured = neverLogsCapture(function () use ($scenario, $token, $unknown): void {
        match ($scenario) {
            'a valid token' => test()->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk(),
            'an unknown token' => test()->postJson(Fx::REDEEM_URL, Fx::redeemBody($unknown))->assertNotFound(),
            'a disabled link' => test()->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertNotFound(),
            'a closed project' => test()->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertForbidden(),
            'a malformed token' => test()->postJson(Fx::REDEEM_URL, Fx::redeemBody($token.'x'))->assertNotFound(),
            'an array token' => test()->postJson(Fx::REDEEM_URL, Fx::redeemBody([]))->assertNotFound(),
            'a token in the query string' => test()->postJson(Fx::REDEEM_URL.'?link_token='.$token, Fx::identity())->assertNotFound(),
            'a throttled request' => (function () use ($token): void {
                config(['reusable_links.redeem.per_link_per_hour' => 1]);
                test()->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();
                test()->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertStatus(429);
            })(),
        };
    });

    neverLogsAssertClean([...$captured['records'], ...$captured['reported']], $token, $scenario);
    neverLogsAssertClean([...$captured['records'], ...$captured['reported']], $unknown, $scenario);
    expect($link->id)->toBeInt();
})->with([
    'a valid token',
    'an unknown token',
    'a disabled link',
    'a closed project',
    'a malformed token',
    'an array token',
    'a token in the query string',
    'a throttled request',
]);

test('a forced mint failure is a reported 500, and neither the report nor its log line shows the token or its hash', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();
    app()->instance(JWTAuth::class, new ThrowingJwtAuth);

    $captured = neverLogsCapture(
        fn () => $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertStatus(500),
    );

    // The control: the failure WAS reported and logged, so an empty search below
    // means "clean", not "nothing was captured".
    expect($captured['reported'])->not->toBe([])
        ->and($captured['records'])->not->toBe([]);
    neverLogsAssertClean([...$captured['records'], ...$captured['reported']], $token, 'mint failure');
    expect(Fx::visitorsOf($link))->toBe([]);
});

test('creating a link for a project in an unsupported language logs a warning that carries no token', function (): void {
    $world = Fx::redeemable(projectAttributes: ['language' => 'xx']);

    $captured = neverLogsCapture(function () use ($world, &$token): void {
        $token = neverLogsCreateLink($world)['token'];
    });

    // The control: the composer's own language warning is the one log line this
    // feature can write on the happy path.
    expect(implode("\n", $captured['records']))->toContain('xx');
    neverLogsAssertClean([...$captured['records'], ...$captured['reported']], $token, 'unsupported language');
});

// ─── Audit, queue, events, cache ─────────────────────────────────────────────

test('the audit rows of a create and a disable carry neither, and redemptions write none', function (): void {
    $world = Fx::redeemable();
    $created = neverLogsCreateLink($world);
    $afterCreate = AuditLog::query()->count();

    foreach (range(1, 3) as $n) {
        $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($created['token']))->assertOk();
    }
    expect(AuditLog::query()->count())->toBe($afterCreate);

    $this->withToken($created['admin'])
        ->deleteJson("/api/projects/{$world['project']->id}/reusable-links/{$created['id']}")
        ->assertNoContent();

    $rows = AuditLog::query()->whereIn('action', ['reusable_link.created', 'reusable_link.disabled'])->get();
    expect($rows)->toHaveCount(2);
    neverLogsAssertClean($rows->map(fn (AuditLog $row): string => (string) json_encode($row->getAttributes()))->all(), $created['token'], 'audit');
});

test('queued jobs, failed_jobs and the dispatched event carry neither', function (): void {
    $world = Fx::redeemable(projectAttributes: [
        'webhook_url' => 'https://receiver.example.test/hook',
        'webhook_secret' => 'whsec_never_logs_test',
        'webhook_events' => ['progress', 'evaluation'],
    ]);
    $created = neverLogsCreateLink($world);

    $events = [];
    Event::listen(ParticipantCreated::class, function (ParticipantCreated $event) use (&$events): void {
        $events[] = $event;
    });

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($created['token']))->assertOk();

    // Every job pushed, of any class, and every raw payload: not only the
    // webhook delivery this project subscribes to.
    $pushed = collect(Queue::pushedJobs())->flatten(1)->pluck('job');

    // The controls: an event and a webhook job really were produced.
    expect($events)->toHaveCount(1)
        ->and(Queue::pushed(DeliverWebhookJob::class))->not->toBeEmpty();

    $serialised = [
        serialize($events[0]),
        (string) json_encode($events[0]),
        ...$pushed->map(fn (object $job): string => serialize($job))->all(),
        ...array_map(fn (array $raw): string => (string) json_encode($raw), Queue::rawPushes()),
        (string) json_encode(DB::table('failed_jobs')->get()),
        (string) json_encode(DB::table('jobs')->get()),
    ];
    neverLogsAssertClean($serialised, $created['token'], 'queue and event');
});

test('no cache key shows the token or its hash', function (): void {
    $world = Fx::redeemable();
    $unknown = ReusableLinkTokenGenerator::generate();
    $created = [];

    $keys = neverLogsCacheKeys(function () use ($world, $unknown, &$created): void {
        $created = neverLogsCreateLink($world);
        test()->postJson(Fx::REDEEM_URL, Fx::redeemBody($created['token']))->assertOk();
        test()->postJson(Fx::REDEEM_URL, Fx::redeemBody($unknown))->assertNotFound();
        test()->postJson(Fx::REDEEM_URL, Fx::redeemBody($world['token']))->assertOk();
    });

    // The control: the rate limiter really did keep per-link counters.
    expect($keys)->not->toBe([]);
    neverLogsAssertClean($keys, $created['token'], 'cache');
    neverLogsAssertClean($keys, $world['token'], 'cache');
    neverLogsAssertClean($keys, $unknown, 'cache');
});

// ─── Bodies ──────────────────────────────────────────────────────────────────

test('only the 201 creation response contains the raw token: list, disable, redeem and session bodies do not', function (): void {
    $world = Fx::redeemable();
    $created = neverLogsCreateLink($world);
    $project = $world['project'];

    // The creation body carries it exactly once, inside the URL.
    expect(substr_count($created['created'], $created['token']))->toBe(1);

    $bodies = [];
    $list = $this->withToken($created['admin'])->getJson("/api/projects/{$project->id}/reusable-links")->assertOk();
    $bodies['list'] = (string) $list->getContent();
    resetAuthGuardState();

    $redeemed = $this->flushHeaders()->postJson(Fx::REDEEM_URL, Fx::redeemBody($created['token']))->assertOk();
    $bodies['redeem'] = (string) $redeemed->getContent();

    $session = $this->withToken((string) $redeemed->json('access_token'))->getJson('/api/candidate/session')->assertOk();
    $bodies['candidate session'] = (string) $session->getContent();
    resetAuthGuardState();

    $disable = $this->withToken($created['admin'])->deleteJson("/api/projects/{$project->id}/reusable-links/{$created['id']}")->assertNoContent();
    $bodies['disable'] = (string) $disable->getContent();
    resetAuthGuardState();

    $relist = $this->withToken($created['admin'])->getJson("/api/projects/{$project->id}/reusable-links")->assertOk();
    $bodies['list after disable'] = (string) $relist->getContent();
    resetAuthGuardState();

    $after = $this->flushHeaders()->postJson(Fx::REDEEM_URL, Fx::redeemBody($created['token']))->assertNotFound();
    $bodies['redeem after disable'] = (string) $after->getContent();

    foreach ($bodies as $label => $body) {
        neverLogsAssertClean([$body], $created['token'], $label);
    }
    // ...and the list does still identify the link, by its public id.
    expect($bodies['list'])->toContain($created['id']);
});

// ─── Storage ─────────────────────────────────────────────────────────────────

test('after a whole lifecycle the raw token is in no table row, and the hash only in the links table', function (): void {
    $world = Fx::redeemable(projectAttributes: [
        'webhook_url' => 'https://receiver.example.test/hook',
        'webhook_secret' => 'whsec_never_logs_test',
        'webhook_events' => ['progress', 'evaluation'],
    ]);
    $created = neverLogsCreateLink($world);
    $hash = ReusableLinkTokenGenerator::hash($created['token']);

    foreach (range(1, 3) as $n) {
        $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($created['token']))->assertOk();
    }
    $this->withToken($created['admin'])
        ->deleteJson("/api/projects/{$world['project']->id}/reusable-links/{$created['id']}")
        ->assertNoContent();

    $withToken = [];
    $withHash = [];
    $scanned = 0;

    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        $quoted = '"'.str_replace('"', '""', $table).'"';
        $scanned++;

        // Whole-row text, so a value in any column of any type (json, text,
        // varchar, a serialized payload) is searched, not only the ones this
        // feature knows about.
        if ((int) DB::selectOne("select count(*) as c from {$quoted} t where position(? in t::text) > 0", [$created['token']])->c > 0) {
            $withToken[] = $table;
        }
        if ((int) DB::selectOne("select count(*) as c from {$quoted} t where position(? in t::text) > 0", [$hash])->c > 0) {
            $withHash[] = $table;
        }
    }

    // The control: the scan really did walk the schema, and the links table
    // (the one place the hash belongs) was found by it.
    expect($scanned)->toBeGreaterThan(50)
        ->and($withHash)->toBe(['reusable_interview_links'])
        ->and($withToken)->toBe([]);
});
