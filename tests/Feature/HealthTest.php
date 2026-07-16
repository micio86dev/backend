<?php

declare(strict_types=1);

use Illuminate\Testing\Fluent\AssertableJson;

it('returns 200 with status ok from the health endpoint', function (): void {
    $response = $this->getJson('/api/health');

    $response->assertStatus(200)
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('status', 'ok')
            ->etc()
        );
});

it('returns machine-readable json (not localized) from the health endpoint', function (): void {
    $response = $this->getJson('/api/health');

    $response->assertStatus(200)
        ->assertExactJson(['status' => 'ok']);
});
