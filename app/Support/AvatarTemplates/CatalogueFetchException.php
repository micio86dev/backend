<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

use RuntimeException;

/**
 * A provider catalogue could not be read.
 *
 * Carries a SAFE code (see `AvatarProviderCatalogue::fetch()`) chosen by this
 * codebase, plus the HTTP status for logs. The exception message is a fixed
 * string and never contains provider response content, so nothing that catches
 * this can leak a key or a vendor error body by echoing it.
 */
final class CatalogueFetchException extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct("Catalogue fetch failed: {$safeCode}");
    }
}
