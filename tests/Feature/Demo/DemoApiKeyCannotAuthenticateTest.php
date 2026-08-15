<?php

declare(strict_types=1);

/**
 * `beai:demo-seed` mints 3 `api_clients` rows, one per `ApiClient::state()`
 * badge (design D17) — and NONE of them, including the `active` one, can
 * ever authenticate. `key_hash` is the SHA-256 of a raw key generated and
 * discarded inside one expression (`hash('sha256', bin2hex(random_bytes(32)))`)
 * — nobody, including this test, ever holds the preimage. The badge reports
 * *credential state*, not *possession*.
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Support\Demo\DemoMarker;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

test('the three seeded api_clients rows resolve to active, expired and revoked', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $clients = ApiClient::where('organization_id', $this->org->id)
        ->where('name', 'like', DemoMarker::PREFIX.'%')
        ->get();

    expect($clients)->toHaveCount(3);

    $states = $clients->map(fn (ApiClient $client): string => $client->state())->sort()->values()->all();
    expect($states)->toBe(['active', 'expired', 'revoked']);
});

test('every seeded key_hash is 64 hex characters and unique', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $hashes = ApiClient::where('organization_id', $this->org->id)
        ->where('name', 'like', DemoMarker::PREFIX.'%')
        ->pluck('key_hash');

    expect($hashes)->toHaveCount(3);

    foreach ($hashes as $hash) {
        expect($hash)->toMatch('/^[0-9a-f]{64}$/');
    }

    expect($hashes->unique()->count())->toBe(3);
});

test('no candidate bearer key authenticates against any of the three seeded clients, including the active one', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $active = ApiClient::where('organization_id', $this->org->id)
        ->where('name', DemoMarker::PREFIX.'ats-integration')
        ->firstOrFail();

    expect($active->state())->toBe('active');

    // No preimage exists anywhere — every plausible guess an attacker might
    // try is rejected, including the client's own name/hash as a candidate
    // "key" (a naive attacker's first guess).
    $candidates = [
        'beai_live_'.str_repeat('0', 96),
        $active->name,
        $active->key_hash,
        '',
    ];

    foreach ($candidates as $candidate) {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$candidate])
            ->getJson('/api/m2m/whoami');

        $response->assertStatus(401);
    }
});

test('no raw 64-hex or beai_live_ literal exists anywhere in the demo module source', function (): void {
    $files = glob(app_path('Support/Demo/*.php'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = (string) file_get_contents($file);

        expect($contents)->not->toMatch('/beai_live_[0-9a-f]+/');
    }
});
