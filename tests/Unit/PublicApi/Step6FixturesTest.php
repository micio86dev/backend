<?php

declare(strict_types=1);

/**
 * `Tests\Helpers\PublicApi\Step6Fixtures` (gga finding 3) — the fixture
 * builder must leave the ambient `TenantResolver` exactly as it found it,
 * the same discipline `App\Providers\TenancyServiceProvider`'s own
 * `Queue::before`/restore hook enforces for every real queued job.
 */

use App\Models\Organization;
use App\Support\Tenancy\TenantResolver;
use Tests\Helpers\PublicApi\Step6Fixtures;

test('buildCompletedScoredParticipant() leaves the ambient TenantResolver exactly as it found it', function (): void {
    $resolver = app(TenantResolver::class);
    expect($resolver->getOrgId())->toBeNull();

    $org = Organization::factory()->create();
    $project = Step6Fixtures::project($org);

    Step6Fixtures::buildCompletedScoredParticipant($org, $project);

    // Not merely "reset to null" by coincidence — the SAME property a real
    // queued job's Queue::before/after pair guarantees: whatever the
    // resolver held BEFORE the call, it holds again AFTER.
    expect($resolver->getOrgId())->toBeNull();
    expect($resolver->isBypass())->toBeFalse();
});
