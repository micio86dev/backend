<?php

declare(strict_types=1);

use Illuminate\Testing\Fluent\AssertableJson;

/**
 * `GET /api/v1/health` — SPEC.md §5.2 "Health: `/v1/health` (unauthenticated,
 * no data) for client monitors" and `public-api/openapi.yaml`'s `getHealth`
 * operation. DB-free by design, mirrors `Feature/HealthTest.php`'s existing
 * `/api/health` coverage but is a SEPARATE contract-governed surface (see
 * `App\Http\Controllers\PublicApi\HealthController`'s docblock).
 */
test('T-CONTRACT-004: GET /api/v1/health returns 200 {"status":"ok"} and matches the contract', function (): void {
    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200)
        ->assertExactJson(['status' => 'ok'])
        ->assertHeader('Content-Type', 'application/json; charset=utf-8')
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('status', 'ok')
            ->etc()
        );

    $this->assertMatchesContract($response, 'GET', '/health');
});
