<?php

declare(strict_types=1);

/**
 * ParticipantStatusGuard fail-closed branch: with no authenticated candidate on
 * the `api-candidate` guard the middleware refuses with 401 and never invokes
 * the downstream handler. The guard normally runs after `auth:api-candidate`,
 * so this is the defensive path if the ordering is ever broken.
 */

use App\Http\Middleware\ParticipantStatusGuard;
use Illuminate\Http\Request;

test('an unauthenticated request is refused 401 and the next handler is never reached', function (): void {

    $reached = false;

    $response = (new ParticipantStatusGuard)->handle(
        Request::create('/api/candidate/interview/utterance', 'POST'),
        function () use (&$reached) {
            $reached = true;

            return response()->json(['ok' => true]);
        },
    );

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getContent())->toContain('Unauthenticated.')
        ->and($reached)->toBeFalse();
});
