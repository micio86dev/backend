<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Jobs\SendPasswordResetLinkJob;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/auth/forgot-password` — request a reset link
 * (self-service-password-reset AD-3).
 *
 * THIS METHOD HAS NO BRANCHES, AND THAT IS THE DESIGN
 * ---------------------------------------------------
 * It does not look the user up. It does not check whether they are
 * deactivated. It does not touch the token table or the mailer. It dispatches
 * and returns, so the response is identical — same status, same body, same
 * headers, same wall-clock cost — for an existing address, an unknown one, and
 * a deactivated one.
 *
 * Any `if` added here re-opens the oracle. A lookup is a database round trip;
 * a send is a network round trip to Resend. Either one makes the two branches
 * distinguishable with a stopwatch, no statistics required, no matter how
 * carefully the bodies are matched. Every one of those decisions lives in
 * `SendPasswordResetLinkJob`, off-request.
 *
 * 202 Accepted, not 200: it is literally true. The work has been accepted for
 * processing and nothing about its outcome is being asserted — which is also
 * the only honest thing to say to a caller we refuse to tell whether the
 * account exists.
 *
 * Anti-enumeration is already the house contract here: `AuthController::login`
 * returns one generic 401 for wrong-password, unknown-email and deactivated
 * alike, and `backoffice/app/pages/login.vue` states in a comment that the form
 * must not become an enumeration oracle. This endpoint joins that contract.
 */
final class ForgotPasswordController extends Controller
{
    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        SendPasswordResetLinkJob::dispatch((string) $request->validated('email'));

        // Says nothing about the address. Deliberately not localized per
        // recipient — there is no recipient to localize for, since we refuse
        // to admit whether one exists.
        return response()->json([
            'message' => 'If an account exists for that address, a password reset link has been sent.',
        ], Response::HTTP_ACCEPTED);
    }
}
