<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

/**
 * Marker interface for a model that carries a BEAI Public API (`/v1`)
 * external id — public-api step 4, G-05.
 *
 * Exists purely so `App\Support\PublicApi\PublicId::encode()` can type its
 * parameter as `Model&PubliclyIdentifiable` — a real, statically-checkable
 * intersection type — rather than `Model` plus a runtime
 * `method_exists()` probe. `App\Models\Concerns\HasPublicId` supplies the
 * `public_id` column plumbing; a using model additionally declares `implements
 * PubliclyIdentifiable` and implements `publicIdPrefix()` (the trait
 * declares it `abstract`, so a using model that forgets `implements` here
 * still fails to compile — the trait requirement and this interface always
 * agree by construction).
 */
interface PubliclyIdentifiable
{
    /**
     * The prefix `PublicId::encode()`/`decode()` join to/strip from this
     * model's bare `public_id` column — e.g. `org_`, `prj_`.
     */
    public static function publicIdPrefix(): string;
}
