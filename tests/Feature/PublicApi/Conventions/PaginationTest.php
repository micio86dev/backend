<?php

declare(strict_types=1);

/**
 * BEAI Public API (`/v1`) cursor pagination (public-api step 3) — SPEC.md
 * §3.2 "Pagination".
 *
 * T-CONV-001: stability under inserts. T-CONV-002: limit bounds.
 * T-CONV-003: invalid/tampered cursor → 400 invalid_cursor. T-CONV-012:
 * expand limits (HTTP level; unit coverage lives in
 * tests/Unit/PublicApi/ExpandTest.php).
 *
 * `ApiClient` is the paginated resource for these probes — it already has
 * `created_at`/`id` and a factory, and carries no meaning of its own here
 * beyond "a row this org owns"; no public `/v1` resource exists yet
 * (step 4 adds the first one).
 */

use App\Http\Middleware\PublicApi\AssignRequestId;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Support\PublicApi\CursorPage;
use App\Support\PublicApi\Expand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware([AssignRequestId::class])->prefix('api/v1')->group(function (): void {
        Route::get('/_probe/clients', function (Request $request) {
            $envelope = CursorPage::paginate(
                ApiClient::query(),
                $request,
                fn (ApiClient $client): array => ['id' => $client->id, 'name' => $client->name],
            );

            return response()->json($envelope);
        });

        Route::get('/_probe/expand', function (Request $request) {
            return response()->json(['expand' => Expand::parse($request, ['project'])]);
        });
    });
});

test('T-CONV-001: a row inserted after fetching page 1 never shifts page 2', function (): void {
    $org = Organization::factory()->create();

    // Oldest (client1) to newest (client5) — 5..1 is the expected desc order.
    $clients = [];
    foreach (range(1, 5) as $i) {
        $clients[$i] = ApiClient::factory()->create([
            'organization_id' => $org->id,
            'name' => "client{$i}",
            'created_at' => now()->subMinutes(5 - $i),
        ]);
    }

    $page1 = $this->getJson('/api/v1/_probe/clients?limit=2')->json();

    expect(array_column($page1['data'], 'name'))->toBe(['client5', 'client4']);
    expect($page1['has_more'])->toBeTrue();
    expect($page1['next_cursor'])->toBeString();

    // Insert a row NEWER than everything already paginated — this is the
    // "row inserted after fetching page 1" case: it must never appear on
    // page 2, and must never push client3/client2 out of position.
    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'name' => 'client6-inserted-later',
        'created_at' => now(),
    ]);

    $page2 = $this->getJson('/api/v1/_probe/clients?limit=2&cursor='.urlencode((string) $page1['next_cursor']))->json();

    expect(array_column($page2['data'], 'name'))->toBe(['client3', 'client2']);
    expect($page2['has_more'])->toBeTrue();

    $page3 = $this->getJson('/api/v1/_probe/clients?limit=2&cursor='.urlencode((string) $page2['next_cursor']))->json();

    expect(array_column($page3['data'], 'name'))->toBe(['client1']);
    expect($page3['has_more'])->toBeFalse();
    expect($page3['next_cursor'])->toBeNull();
});

test('T-CONV-002: limit=0 → 422 validation_failed (min)', function (): void {
    $response = $this->getJson('/api/v1/_probe/clients?limit=0');

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.0.field', 'limit')
        ->assertJsonPath('errors.0.code', 'min');
    $this->assertProblemMatchesContract($response, 422);
});

test('T-CONV-002: limit=101 → 422 validation_failed (max)', function (): void {
    $response = $this->getJson('/api/v1/_probe/clients?limit=101');

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.0.field', 'limit')
        ->assertJsonPath('errors.0.code', 'max');
});

test('T-CONV-002: limit=abc → 422 validation_failed (integer)', function (): void {
    $response = $this->getJson('/api/v1/_probe/clients?limit=abc');

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.0.field', 'limit')
        ->assertJsonPath('errors.0.code', 'integer');
});

test('T-CONV-002: limit=100 is accepted', function (): void {
    $org = Organization::factory()->create();
    ApiClient::factory()->count(3)->create(['organization_id' => $org->id]);

    $response = $this->getJson('/api/v1/_probe/clients?limit=100');

    $response->assertOk();
    expect($response->json('has_more'))->toBeFalse();
});

test('T-CONV-002: no ?limit= defaults to 25', function (): void {
    $org = Organization::factory()->create();
    ApiClient::factory()->count(30)->create(['organization_id' => $org->id]);

    $response = $this->getJson('/api/v1/_probe/clients');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(25);
    expect($response->json('has_more'))->toBeTrue();
});

test('T-CONV-003: a syntactically invalid cursor → 400 invalid_cursor', function (): void {
    $response = $this->getJson('/api/v1/_probe/clients?cursor=not-valid-base64url!!!');

    $response->assertStatus(400)->assertJsonPath('code', 'invalid_cursor');
    $this->assertProblemMatchesContract($response, 400);
});

test('T-CONV-003: a tampered (bit-flipped) cursor → 400 invalid_cursor', function (): void {
    $org = Organization::factory()->create();
    ApiClient::factory()->count(2)->create(['organization_id' => $org->id]);

    $page1 = $this->getJson('/api/v1/_probe/clients?limit=1')->json();
    $cursor = (string) $page1['next_cursor'];

    // Flip the first character — still valid base64url, but the HMAC will
    // no longer match.
    $tampered = ($cursor[0] === 'a' ? 'b' : 'a').substr($cursor, 1);

    $response = $this->getJson('/api/v1/_probe/clients?cursor='.urlencode($tampered));

    $response->assertStatus(400)->assertJsonPath('code', 'invalid_cursor');
});

test('T-CONV-012: expand with 4 names → 400 invalid_expand', function (): void {
    $response = $this->getJson('/api/v1/_probe/expand?expand=a,b,c,d');

    $response->assertStatus(400)->assertJsonPath('code', 'invalid_expand');
    $this->assertProblemMatchesContract($response, 400);
});

test('T-CONV-012: expand with an unknown name → 400 invalid_expand', function (): void {
    $response = $this->getJson('/api/v1/_probe/expand?expand=not_a_real_relation');

    $response->assertStatus(400)->assertJsonPath('code', 'invalid_expand');
});

// ─── CursorPage::decodeCursor() malformed-payload branches — every distinct
// "this cursor cannot possibly be trusted" reason, each independently
// reachable from a hand-crafted (but correctly HMAC-signed, where
// applicable) cursor value. ─────────────────────────────────────────────────

test('T-CONV-003: a non-string cursor (array) → 400 invalid_cursor', function (): void {
    $response = $this->getJson('/api/v1/_probe/clients?cursor[]=x');

    $response->assertStatus(400)->assertJsonPath('code', 'invalid_cursor');
});

test('T-CONV-003: valid base64url decoding to a string with no signature separator → 400 invalid_cursor', function (): void {
    $cursor = rtrim(strtr(base64_encode('no-dot-separator-here'), '+/', '-_'), '=');

    $response = $this->getJson('/api/v1/_probe/clients?cursor='.urlencode($cursor));

    $response->assertStatus(400)->assertJsonPath('code', 'invalid_cursor');
});

test('T-CONV-003: a correctly-signed payload with no |id separator → 400 invalid_cursor', function (): void {
    $payload = 'no-pipe-here';
    $cursor = signedCursorFor($payload);

    $response = $this->getJson('/api/v1/_probe/clients?cursor='.urlencode($cursor));

    $response->assertStatus(400)->assertJsonPath('code', 'invalid_cursor');
});

test('T-CONV-003: a correctly-signed payload with an empty id → 400 invalid_cursor', function (): void {
    $payload = '2026-09-24T15:45:00.000000Z|';
    $cursor = signedCursorFor($payload);

    $response = $this->getJson('/api/v1/_probe/clients?cursor='.urlencode($cursor));

    $response->assertStatus(400)->assertJsonPath('code', 'invalid_cursor');
});

test('T-CONV-003: a correctly-signed payload with an unparseable timestamp → 400 invalid_cursor', function (): void {
    $payload = 'not-a-real-timestamp|123';
    $cursor = signedCursorFor($payload);

    $response = $this->getJson('/api/v1/_probe/clients?cursor='.urlencode($cursor));

    $response->assertStatus(400)->assertJsonPath('code', 'invalid_cursor');
});

/**
 * Builds a syntactically valid, correctly HMAC-signed cursor for an
 * arbitrary payload — mirrors `CursorPage::encodeCursor()`'s own format
 * exactly, so these tests can reach `decodeCursor()`'s branches AFTER the
 * signature check without also depending on `CursorPage`'s private methods.
 */
function signedCursorFor(string $payload): string
{
    $signature = hash_hmac('sha256', $payload, (string) config('app.key'));

    return rtrim(strtr(base64_encode($signature.'.'.$payload), '+/', '-_'), '=');
}
