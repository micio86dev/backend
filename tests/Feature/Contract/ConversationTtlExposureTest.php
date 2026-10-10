<?php

declare(strict_types=1);

/**
 * RED/GREEN — tavus-single-session-interview API-05.2 (design N11).
 *
 * `conversation_ttl_seconds` is a candidate-start field, like `conversation_id` and
 * `continuation`: the internal spec documents it, the public v1 spec never does.
 */
test('conversation_ttl_seconds is documented on the candidate start and absent from the public spec', function (): void {
    $internal = (string) file_get_contents(base_path('openapi.json'));
    $public = (string) file_get_contents(base_path('openapi.v1.json'));

    expect($internal)->toContain('"conversation_ttl_seconds"')
        ->and($public)->not->toContain('conversation_ttl_seconds');
});
