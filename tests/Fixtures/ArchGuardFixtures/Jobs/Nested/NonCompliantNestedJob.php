<?php

declare(strict_types=1);

namespace Tests\Fixtures\ArchGuardFixtures\Jobs\Nested;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Fixture for QueuedJobTenantContextArchTest.php's recursive-discovery proof test.
 *
 * A ShouldQueue class living in a NESTED subdirectory that never establishes any
 * tenant boundary — this is exactly the blind spot a non-recursive
 * `glob('app/Jobs/*.php')` scan would silently miss. MUST be flagged as a violation
 * once discovery is recursive.
 *
 * IMPORTANT: this docblock must never spell out the guarded string (Tenant + Context
 * + Scope + "::") — the guard's own check is a bare `str_contains()` over the raw
 * file source, so writing that exact substring anywhere in this file — even in a
 * comment explaining its absence — would make this "non-compliant" fixture look
 * compliant and silently invalidate the proof.
 */
final class NonCompliantNestedJob implements ShouldQueue
{
    public function handle(): void
    {
        // Intentionally empty — no tenancy boundary of any kind.
    }
}
