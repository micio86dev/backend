<?php

declare(strict_types=1);

/**
 * Standalone (NOT autoloaded, NOT part of the app) actor process for the
 * reusable-interview-links concurrency tests (B3b.8).
 *
 * Launched via `proc_open` from `tests/Helpers/ReusableLinkConcurrency.php` as a
 * genuinely separate OS process: it boots the whole application, with its OWN
 * Postgres backend session, and handles ONE real HTTP request through the HTTP
 * kernel, so a race between two of these is a race between two real requests,
 * middleware, limiter and transaction included.
 *
 * Usage: `php reusable_link_http_actor.php '<json spec>'` where the spec is
 *
 *   {"method": "POST", "uri": "/api/reusable-links/redeem",
 *    "ip": "10.0.0.1", "headers": {"Authorization": "Bearer ..."},
 *    "body": {"link_token": "..."}}
 *
 * It prints exactly one line to STDOUT, `{"status": <int>, "body": "<string>"}`,
 * and exits 0. The database, cache and queue are chosen by the environment the
 * parent hands over (a throwaway database, an in-process array cache, a sync
 * queue); nothing here reads any configuration of its own.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$spec = json_decode((string) ($argv[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);

$app = require $root.'/bootstrap/app.php';

$server = ['REMOTE_ADDR' => $spec['ip'] ?? '127.0.0.1', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
foreach (($spec['headers'] ?? []) as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', (string) $name))] = $value;
}

$request = Request::createFromBase(SymfonyRequest::create(
    (string) $spec['uri'],
    (string) $spec['method'],
    [],
    [],
    [],
    $server,
    isset($spec['body']) ? json_encode($spec['body'], JSON_THROW_ON_ERROR) : null,
));

$response = $app->make(Kernel::class)->handle($request);

fwrite(STDOUT, json_encode(['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()], JSON_THROW_ON_ERROR).PHP_EOL);
exit(0);
