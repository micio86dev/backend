<?php

declare(strict_types=1);

namespace App\Exceptions\PublicApi;

use RuntimeException;

/**
 * Thrown by `App\Support\PublicApi\Expand` when `?expand=` names more than 3
 * sub-resources or names one that is not in the caller's allow-list
 * (public-api step 3, SPEC.md §3.2 "Expansion"). Mapped by
 * `App\Support\PublicApi\PublicApiExceptionRenderer` to `400 invalid_expand`.
 */
final class InvalidExpandException extends RuntimeException {}
