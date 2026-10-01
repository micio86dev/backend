<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Actions\ReusableLinks\RedeemReusableInterviewLink;
use App\Actions\ReusableLinks\RedemptionStatus;
use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ReusableLinkRedeemController (reusable-interview-links, design AD-8).
 *
 * PUBLIC endpoint, no auth guard and no TenantContext, same isolation as
 * `/sso/exchange` and `/embed/exchange`:
 *
 *   POST /api/reusable-links/redeem   {"link_token": "beai_rl_..."}
 *
 * The token is read ONLY from the JSON (or form) BODY field `link_token`: not
 * from the query string, not from a header, not from a field named `token`. The
 * default guard's token parser reads a `token` input, and an array-shaped one
 * once made a sibling endpoint answer 500 before its controller ran; the field
 * name and the NAMED limiter on the route are part of the contract. Nothing
 * here touches `$request->user()`, and an `Authorization` header is ignored.
 *
 * Two answers, and the gap between them is the point:
 *   - 404 `{"message":"Not found."}` for EVERY token that is not redeemable
 *     (unknown, malformed, disabled, project gone, another credential type),
 *     from ONE helper, so the caller cannot tell which it was.
 *   - 403 `{"message":"Access denied.","redirect_url":...}` for a valid, enabled
 *     token whose project is closed. Only a holder of a working token can reach
 *     it, so it discloses nothing they lack (the SSO exchange's 403 shape).
 *
 * Never `firstOrFail()` / `findOrFail()` here: a ModelNotFoundException names
 * the model. No `validate()` either: a 422 would tell a prober "malformed" from
 * "unknown".
 */
final class ReusableLinkRedeemController extends Controller
{
    private const GENERIC_403 = 'Access denied.';

    public function __construct(
        private readonly RedeemReusableInterviewLink $redeemLink,
    ) {}

    /**
     * Redeem a reusable interview link.
     *
     * Exchanges the secret of a reusable interview link for a candidate access
     * token. Every successful call starts a NEW anonymous candidate in the
     * link's project, in the link's language, so one link serves any number of
     * people. The link never expires: it works until it is disabled or its
     * project closes. The token comes only from the `link_token` body field.
     *
     * A token that is unknown, malformed, or disabled is answered with the same
     * 404, so a response never reveals whether a link exists. A 403 means the
     * link is valid but its project is not open for interviews right now.
     */
    #[BodyParameter(
        'link_token',
        description: 'The link token from the reusable link URL: `beai_rl_` followed by 43 URL-safe base64 characters (51 characters in all).',
        required: true,
        type: 'string',
    )]
    #[Response(200, description: 'A candidate access token for a new anonymous candidate in the link\'s project.', type: 'array{access_token: string}')]
    #[Response(403, description: 'The link is valid but its project is not open for interviews. `redirect_url` is the project\'s error redirect, when it has one.', type: 'array{message: string, redirect_url: string|null}')]
    #[Response(404, description: 'No such link: the token is unknown, malformed or disabled. The body is identical for every such case.', type: 'array{message: string}')]
    #[Response(429, description: 'Too many attempts. Retry after the number of seconds in the `Retry-After` header.', type: 'array{message: string}')]
    public function redeem(Request $request): JsonResponse
    {
        // `post()`, not `input()`: `input()` also merges the query string, and a
        // token in a URL would end up in access logs. The BODY is the only
        // carrier.
        $outcome = $this->redeemLink->handle($request->post('link_token'));

        return match ($outcome->status) {
            RedemptionStatus::Redeemed => response()->json(['access_token' => $outcome->accessToken], 200),
            RedemptionStatus::Refused => response()->json([
                'message' => self::GENERIC_403,
                'redirect_url' => $outcome->project?->error_redirect_url,
            ], 403),
            RedemptionStatus::NotFound => $this->notFound(),
        };
    }

    /**
     * The ONE 404 every non-redeemable token gets. Byte-identical by
     * construction; it contains nothing derived from the input.
     */
    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not found.'], 404);
    }
}
