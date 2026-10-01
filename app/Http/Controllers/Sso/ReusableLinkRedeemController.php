<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Actions\ReusableLinks\RedeemReusableInterviewLink;
use App\Actions\ReusableLinks\RedemptionStatus;
use App\Actions\ReusableLinks\VisitorIdentity;
use App\Http\Controllers\Controller;
use App\Rules\NotPlaceholderEmail;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * ReusableLinkRedeemController (reusable-interview-links, design AD-8).
 *
 * PUBLIC endpoint, no auth guard and no TenantContext, same isolation as
 * `/sso/exchange` and `/embed/exchange`:
 *
 *   POST /api/reusable-links/redeem
 *        {"link_token": "beai_rl_...", "display_name": "...", "email": "..."}
 *
 * The token is read ONLY from the JSON (or form) BODY field `link_token`: not
 * from the query string, not from a header, not from a field named `token`. The
 * default guard's token parser reads a `token` input, and an array-shaped one
 * once made a sibling endpoint answer 500 before its controller ran; the field
 * name and the NAMED limiter on the route are part of the contract. Nothing
 * here touches `$request->user()`, and an `Authorization` header is ignored.
 *
 * The visitor's identity (`display_name`, `email`) is read from the BODY too,
 * for the same reason: a query string ends up in access logs, and a name and an
 * address are personal data. It is self-declared and NOT verified.
 *
 * Four refusals, and the gap between them is the point:
 *   - 422 (the framework's standard body) when the name or the email is missing
 *     or invalid. It is decided FIRST and from the identity alone, so it is
 *     byte-identical for any `link_token` (valid, unknown, malformed, disabled
 *     or absent) and never reveals which one was sent.
 *   - 404 `{"message":"Not found."}` for EVERY token that is not redeemable
 *     (unknown, malformed, disabled, project gone, another credential type),
 *     from ONE helper, so the caller cannot tell which it was.
 *   - 409 `{"message":"duplicate_enrolment"}` for a valid identity whose email is
 *     already enrolled in the link's project. Only a holder of a working token
 *     on an open project can reach it, it carries nothing about the existing
 *     participant, and the enrolment is never resumed.
 *   - 403 `{"message":"Access denied.","redirect_url":...}` for a valid, enabled
 *     token whose project is closed. Only a holder of a working token can reach
 *     it, so it discloses nothing they lack (the SSO exchange's 403 shape).
 *
 * Never `firstOrFail()` / `findOrFail()` here: a ModelNotFoundException names
 * the model. Validation covers ONLY the two identity fields: the token is never
 * validated, so "malformed" stays indistinguishable from "unknown". It is also
 * `Validator::make($request->post(), ...)`, never `$request->validate()`, which
 * would merge the query string into what it validates.
 */
final class ReusableLinkRedeemController extends Controller
{
    private const GENERIC_403 = 'Access denied.';

    /**
     * The machine code of the refusal of an email already enrolled in the
     * link's project. The term is the one the public API already publishes.
     */
    private const DUPLICATE_ENROLMENT = 'duplicate_enrolment';

    public function __construct(
        private readonly RedeemReusableInterviewLink $redeemLink,
    ) {}

    /**
     * Redeem a reusable interview link.
     *
     * Exchanges the secret of a reusable interview link for a candidate access
     * token. Every successful call starts a NEW candidate in the link's project,
     * in the link's language, identified by the name and email in the body
     * (self-declared, not verified), so one link serves any number of people.
     * The link never expires: it works until it is disabled or its project
     * closes. The token comes only from the `link_token` body field.
     *
     * The name and the email are checked first: a missing or invalid one is a
     * 422 whatever the token is. A valid name and email beside a token that is
     * unknown, malformed, or disabled is answered with the same 404, so a
     * response never reveals whether a link exists. A 403 means the link is valid
     * but its project is not open for interviews right now. A 409 means the email
     * is already enrolled in the link's project: nothing is created and the
     * existing enrolment is never resumed.
     */
    #[BodyParameter(
        'link_token',
        description: 'The link token from the reusable link URL: `beai_rl_` followed by 43 URL-safe base64 characters (51 characters in all).',
        required: true,
        type: 'string',
    )]
    #[Response(200, description: 'A candidate access token for the new candidate in the link\'s project.', type: 'array{access_token: string}')]
    #[Response(403, description: 'The link is valid but its project is not open for interviews. `redirect_url` is the project\'s error redirect, when it has one.', type: 'array{message: string, redirect_url: string|null}')]
    #[Response(404, description: 'No such link: with a valid name and email, the token is unknown, malformed or disabled. The body is identical for every such case.', type: 'array{message: string}')]
    #[Response(409, description: 'This email is already enrolled in the link\'s project. Nothing was created, and the existing enrolment is neither resumed nor described.', type: 'array{message: string}')]
    #[Response(429, description: 'Too many attempts. Retry after the number of seconds in the `Retry-After` header.', type: 'array{message: string}')]
    public function redeem(Request $request): JsonResponse
    {
        // `post()`, not `input()` or `validate()`: both also merge the query
        // string, and a token or an address in a URL would end up in access
        // logs. The BODY is the only carrier.
        $validated = Validator::make($request->post(), [
            'display_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new NotPlaceholderEmail],
        ])->validate();

        $outcome = $this->redeemLink->handle(
            $request->post('link_token'),
            VisitorIdentity::fromValidated($validated['display_name'], $validated['email']),
        );

        return match ($outcome->status) {
            RedemptionStatus::Redeemed => response()->json(['access_token' => $outcome->accessToken], 200),
            RedemptionStatus::Refused => response()->json([
                'message' => self::GENERIC_403,
                'redirect_url' => $outcome->project?->error_redirect_url,
            ], 403),
            RedemptionStatus::NotFound => $this->notFound(),
            RedemptionStatus::Duplicate => response()->json(['message' => self::DUPLICATE_ENROLMENT], 409),
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
