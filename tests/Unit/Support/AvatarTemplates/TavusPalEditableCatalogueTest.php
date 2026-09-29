<?php

declare(strict_types=1);

/**
 * `editable` on a Tavus persona in the catalogue.
 *
 * Definition: a persona is EDITABLE when its id is in Tavus's own
 * `GET /v2/pals?persona_type=user` list (personas the account authored), NOT
 * editable when it is in `persona_type=system` (Tavus stock personas), and
 * UNKNOWN (`null`) otherwise. Unknown is never promoted to true: some personas
 * appear in neither list (observed live 2026-09-29: the unfiltered list held 46,
 * `user` 10, `system` 30) and PATCH on one of them answers 400 "Invalid
 * persona_id".
 *
 * Every provider call is faked; a stray request fails the run.
 */

use App\Support\AvatarTemplates\AvatarProviderCatalogue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** @param  list<string>  $ids */
function palPage(array $ids): array
{
    return [
        'data' => array_map(fn (string $id): array => ['pal_id' => $id, 'pal_name' => 'Persona '.$id], $ids),
        'total_count' => count($ids),
    ];
}

beforeEach(function (): void {
    config(['interview.tavus.api_key' => 'SUPER_SECRET_TAVUS_KEY_12345']);
    Cache::flush();
    Http::preventStrayRequests();
});

/** @return array<string, ?bool> */
function palEditableById(): array
{
    $items = AvatarProviderCatalogue::fetch('tavus', 'pal')['items'];

    return array_column($items, 'editable', 'id');
}

test('a persona in the user list is editable, in the system list is not, in neither is unknown', function (): void {
    Http::fake([
        '*persona_type=user*' => Http::response(palPage(['p_user']), 200),
        '*persona_type=system*' => Http::response(palPage(['p_system']), 200),
        'tavusapi.com/v2/pals*' => Http::response(palPage(['p_user', 'p_system', 'p_orphan']), 200),
    ]);

    expect(palEditableById())->toBe(['p_user' => true, 'p_system' => false, 'p_orphan' => null]);
});

test('when the user-list call fails nothing is reported editable, whatever else is known', function (): void {
    Http::fake([
        '*persona_type=user*' => Http::response([], 500),
        '*persona_type=system*' => Http::response(palPage(['p_system']), 200),
        'tavusapi.com/v2/pals*' => Http::response(palPage(['p_user', 'p_system']), 200),
    ]);

    expect(palEditableById())->toBe(['p_user' => null, 'p_system' => false]);
});

test('when the user-list call times out the catalogue still answers, all unknown', function (): void {
    Http::fake([
        '*persona_type=user*' => fn () => throw new ConnectionException('timed out'),
        '*persona_type=system*' => fn () => throw new ConnectionException('timed out'),
        'tavusapi.com/v2/pals*' => Http::response(palPage(['p_user']), 200),
    ]);

    $result = AvatarProviderCatalogue::fetch('tavus', 'pal');

    expect($result['status'])->toBe('ok')
        ->and(array_column($result['items'], 'editable', 'id'))->toBe(['p_user' => null]);
});

test('a persona present in both lists is editable: the account authored it', function (): void {
    Http::fake([
        '*persona_type=user*' => Http::response(palPage(['p_both']), 200),
        '*persona_type=system*' => Http::response(palPage(['p_both']), 200),
        'tavusapi.com/v2/pals*' => Http::response(palPage(['p_both']), 200),
    ]);

    expect(palEditableById())->toBe(['p_both' => true]);
});

test('the persona list still exposes only id, name and editable — never the prompt or layers', function (): void {
    Http::fake([
        '*persona_type=*' => Http::response(palPage([]), 200),
        'tavusapi.com/v2/pals*' => Http::response([
            'data' => [['pal_id' => 'p1', 'pal_name' => 'Interviewer', 'system_prompt' => 'CONFIDENTIAL', 'layers' => ['llm' => ['api_key' => 'SECRET_LAYER_KEY']]]],
            'total_count' => 1,
        ], 200),
    ]);

    $result = AvatarProviderCatalogue::fetch('tavus', 'pal');

    expect($result['items'][0])->toMatchArray(['id' => 'p1', 'name' => 'Interviewer', 'label' => 'Interviewer', 'editable' => null])
        ->and(json_encode($result))->not->toContain('CONFIDENTIAL')->not->toContain('SECRET_LAYER_KEY');
});

test('non-persona resources carry no editable key', function (): void {
    Http::fake(['tavusapi.com/v2/voices*' => Http::response(['data' => [['voice_id' => 'v1', 'voice_name' => 'V', 'status' => 'completed']], 'total_count' => 1], 200)]);

    expect(AvatarProviderCatalogue::fetch('tavus', 'voice')['items'][0])->not->toHaveKey('editable');
});
