<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;

/**
 * Guarantee that an organization owns at least one FrameworkVersion.
 *
 * `POST /api/projects` only accepts a `framework_version_id` belonging to the
 * caller's own organization (project-config spec, "Framework-Version
 * Reference-Pin"), and `GET /api/framework/versions` lists only the tenant's
 * own versions. Nothing used to create one when an organization was created,
 * so a brand-new client could not create its first project.
 *
 * The row is deliberately minimal: unlocked (only project creation locks a
 * version) and pinned to the latest PUBLISHED catalogue revision, never a
 * draft. With no published revision (an unseeded platform) nothing is
 * created: a version with a null pin would reintroduce the gap the model's
 * pin guard exists to close. The backfill command or a later deploy retries.
 *
 * Idempotent: an organization with ANY version, locked or not, is left alone.
 */
final class EnsureDefaultFrameworkVersion
{
    public const VERSION = '1.0.0';

    public const LABEL = 'Default framework version';

    /**
     * @return bool true when a version was created, false when none was needed or none could be pinned
     */
    public function ensure(Organization $organization): bool
    {
        // Check and create share ONE tenant context: the ordinary scoped
        // query, no scope strip, and no ambient tenant relied upon.
        return (bool) TenantContextScope::runFor($organization->id, static function () use ($organization): bool {
            if (FrameworkVersion::query()->exists()) {
                return false;
            }

            $revision = FrameworkCatalogRevision::latestPublished();

            if ($revision === null) {
                return false;
            }

            FrameworkVersion::create([
                'organization_id' => $organization->id,
                'version' => self::VERSION,
                'label' => self::LABEL,
                'revision_id' => $revision->id,
            ]);

            return true;
        });
    }
}
