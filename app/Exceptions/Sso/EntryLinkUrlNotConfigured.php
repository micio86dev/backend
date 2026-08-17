<?php

declare(strict_types=1);

namespace App\Exceptions\Sso;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown by `EntryLinkUrlComposer::compose()` when
 * `config('interview.candidate_app_url')` is null, empty, or not an absolute
 * http(s) URL (operator-interview-link, design D3).
 *
 * Thrown AT COMPOSE TIME — never at boot (`AppServiceProvider::boot()`) or at
 * config resolution (`config/interview.php` itself) — because either of those
 * would take down every unrelated endpoint (login, `/health`, ...) the moment
 * this one feature var is unset, exactly on the first deploy after this
 * change ships. render() returns HTTP 500 with a specific, actionable
 * message — deliberately NOT suppressed from Sentry (bootstrap/app.php);
 * being noisy about a misconfigured deploy is the point.
 *
 * REQ: CANDIDATE_APP_URL Fails Loud When Unset
 *      (openspec/changes/operator-interview-link/specs/participant-sso/spec.md)
 */
final class EntryLinkUrlNotConfigured extends RuntimeException
{
    public function __construct(string $message = 'Entry link URL is not configured.')
    {
        parent::__construct($message);
    }

    /**
     * Render as HTTP 500 with a specific, actionable, non-localized message.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
