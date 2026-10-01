<?php

declare(strict_types=1);

namespace App\Actions\ReusableLinks;

use App\Models\ReusableInterviewLink;

/**
 * The outcome of creating a reusable interview link
 * (reusable-interview-links, design AD-6).
 *
 * `entryUrl` is the ONLY place the raw token exists once the action returns:
 * the row holds its hash and a display prefix, nothing else. The caller hands
 * it to the operator in the single creation response and drops it; nothing may
 * log, cache, queue or audit this value.
 */
final readonly class CreatedReusableLink
{
    public function __construct(
        public ReusableInterviewLink $link,
        public string $entryUrl,
    ) {}
}
