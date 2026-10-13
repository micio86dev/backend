<?php

declare(strict_types=1);

/**
 * `beai:storage-selftest` proves a configured disk round-trips: write, exists,
 * presign, fetch over HTTP, delete, missing. It runs here against a fake disk with
 * a stubbed presigner and HTTP layer; what these tests pin is which failure it
 * reports and that a failed probe never leaves its throwaway object behind.
 */

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function fakeDiskWithPresigner(): void
{
    $disk = Storage::fake();
    $disk->buildTemporaryUrlsUsing(fn (string $path) => 'https://bucket.example.test/'.$path);
}

/**
 * @return list<string> every key still on the fake disk
 */
function storedKeys(): array
{
    return Storage::allFiles();
}

test('a healthy disk passes every step and leaves nothing behind', function (): void {
    fakeDiskWithPresigner();
    Http::fake(fn (Request $request) => Http::response(Storage::get(ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/'))));

    $this->artisan('beai:storage-selftest')
        ->expectsOutputToContain('write: OK')
        ->expectsOutputToContain('exists: OK')
        ->expectsOutputToContain('fetch: OK (byte-for-byte match)')
        ->expectsOutputToContain('delete: OK')
        ->expectsOutputToContain('missing: OK')
        ->expectsOutputToContain('PASSED')
        ->assertSuccessful();

    expect(storedKeys())->toBe([]);
});

test('the probe writes under _selftest/ and never under a real snapshot key', function (): void {
    fakeDiskWithPresigner();
    $seen = null;
    Http::fake(function (Request $request) use (&$seen) {
        $seen = ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/');

        return Http::response(Storage::get($seen));
    });

    $this->artisan('beai:storage-selftest')->assertSuccessful();

    expect($seen)->toStartWith('_selftest/')->toEndWith('.jpg');
});

test('a non-2xx answer from the presigned URL fails the fetch step and still cleans up', function (): void {
    fakeDiskWithPresigner();
    Http::fake(['*' => Http::response('denied', 403)]);

    $this->artisan('beai:storage-selftest')
        ->expectsOutputToContain('fetch: FAILED — HTTP 403')
        ->assertFailed();

    expect(storedKeys())->toBe([]);
});

test('fetched bytes that differ from what was written fail the probe and still clean up', function (): void {
    fakeDiskWithPresigner();
    Http::fake(['*' => Http::response('not what was written', 200)]);

    $this->artisan('beai:storage-selftest')
        ->expectsOutputToContain('fetched bytes do not match')
        ->assertFailed();

    expect(storedKeys())->toBe([]);
});

test('a network failure while fetching is reported as a failure, not a crash, and cleans up', function (): void {
    fakeDiskWithPresigner();
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 6: could not resolve host')]);

    $this->artisan('beai:storage-selftest')
        ->expectsOutputToContain('storage-selftest FAILED: cURL error 6')
        ->assertFailed();

    expect(storedKeys())->toBe([]);
});
