<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\ReusableInterviewLink;
use App\Models\User;
use App\Support\PublicApi\PublicId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ReusableInterviewLinkResource (reusable-interview-links, design AD-15).
 *
 * The metadata of a link as an admin sees it. It describes a link, never the
 * credential: the raw token exists only inside the `entry_url` of the single
 * creation response, which the controller adds as a sibling of `data`, not
 * through this resource.
 *
 * SECURITY INVARIANT: none of these may ever appear here, in any shape:
 *   - the raw token, or `entry_url` (a URL that contains it);
 *   - `token_hash` (the lookup key of a live credential; also `$hidden`);
 *   - an expiry of any kind (a link has none);
 *   - the internal integer id, `organization_id` or `project_id`.
 * `tests/Feature/Contract/ReusableLinkDocumentedTest.php` pins the exported key
 * set, so adding a field here fails a test rather than leaking quietly.
 *
 * The creator is exposed by display name only: nothing consumes the internal
 * user id, so it is not handed out.
 *
 * Scramble does not infer this shape from the body (the model is read through a
 * local assignment), so the hand-written `@scramble-return` below is what the
 * export is built from. `@return` carries the same shape for PHPStan.
 *
 * @mixin ReusableInterviewLink
 */
class ReusableInterviewLinkResource extends JsonResource
{
    /**
     * Describes one reusable interview link.
     *
     * `id` is the public identifier (`rlk_` plus a ULID). `status` is `active`
     * until the link is disabled and `disabled` afterwards; there is no
     * expiry and no way back. `uses_count` counts redemptions and
     * `last_used_at` is the time of the latest one. `created_by` is the display
     * name of the user who created the link, or null when that user no longer
     * exists. `token_prefix` identifies the link in a list and authenticates
     * nothing.
     *
     * @return array{id: string, label: string|null, token_prefix: string, lang: string, status: 'active'|'disabled', uses_count: int, last_used_at: string|null, created_by: array{name: string}|null, created_at: string, disabled_at: string|null}
     *
     * @scramble-return array{id: string, label: string|null, token_prefix: string, lang: string, status: 'active'|'disabled', uses_count: int, last_used_at: string|null, created_by: array{name: string}|null, created_at: string, disabled_at: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var ReusableInterviewLink $link */
        $link = $this->resource;

        /** @var User|null $creator */
        $creator = $link->creator;

        return [
            'id' => PublicId::encode($link),
            'label' => $link->label,
            'token_prefix' => $link->token_prefix,
            'lang' => $link->lang,
            'status' => $link->disabled_at === null ? 'active' : 'disabled',
            'uses_count' => $link->uses_count,
            'last_used_at' => $link->last_used_at?->toISOString(),
            'created_by' => $creator === null ? null : ['name' => $creator->name],
            // `created_at` is never null on a persisted row; the `string` in the
            // return type is `Carbon::toISOString()`'s own nullable signature.
            'created_at' => (string) $link->created_at?->toISOString(),
            'disabled_at' => $link->disabled_at?->toISOString(),
        ];
    }
}
