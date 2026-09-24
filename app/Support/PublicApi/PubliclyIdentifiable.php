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
 * `public_id` column plumbing and declares `publicIdPrefix()` `abstract`,
 * so a using model that forgets to implement that METHOD fails to compile
 * (PHP fatal error: class contains 1 abstract method) — but a using model
 * that implements the method while forgetting `implements
 * PubliclyIdentifiable` on the class itself compiles and runs FINE; PHP
 * never infers an interface from a matching method signature. The trait and
 * this interface do not "always agree by construction" — a using model must
 * still declare BOTH: `use HasPublicId` for the method contract PHP
 * enforces, and `implements PubliclyIdentifiable` for the type PHPStan and
 * `PublicId::encode()`'s signature enforce. Only `PublicId::encode()`
 * rejecting a call at STATIC ANALYSIS time (not a runtime compile error)
 * catches a model that has one but not the other — see the Arch test in
 * `tests/Arch/PublicApi/HasPublicIdArchTest.php`, which pins this pairing
 * directly rather than relying on a false compile-time guarantee.
 */
interface PubliclyIdentifiable
{
    /**
     * The prefix `PublicId::encode()`/`decode()` join to/strip from this
     * model's bare `public_id` column — e.g. `org_`, `prj_`.
     */
    public static function publicIdPrefix(): string;
}
