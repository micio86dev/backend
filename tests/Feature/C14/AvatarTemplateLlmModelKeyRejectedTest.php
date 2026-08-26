<?php

declare(strict_types=1);

/**
 * `llmModel` is no longer an accepted Tavus config key
 * (pluggable-conversation-llm PR P3a, design D3).
 *
 * The binding and the old select would otherwise write the SAME PAL path
 * (`layers/llm/model`) — two writers, one path, last one wins, no error.
 *
 * REQ: avatar-templates "A template may bind one conversation model and one
 *      credential, both or neither" — "llmModel is no longer an accepted
 *      Tavus config key"
 */

use App\Models\Organization;

test('a Tavus config carrying config.llmModel is rejected 422 as unknown', function (): void {
    $org = Organization::factory()->create();
    $token = authTokenForRole($org, 'admin');

    $this->withToken($token)->postJson('/api/avatar-templates', [
        'name' => 'Rejected template',
        'provider' => 'tavus',
        'config' => [
            'faceId' => 'face-abc',
            'palId' => 'pal-def',
            'llmModel' => 'tavus-gemini-2.5-flash',
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['config.llmModel' => 'unknown']);
});
