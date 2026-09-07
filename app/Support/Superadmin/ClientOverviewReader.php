<?php

declare(strict_types=1);

namespace App\Support\Superadmin;

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every client, with the platform-wide statistics the superadmin console
 * needs (superadmin-clients-console, design D1/D2).
 *
 * THE SECOND deliberate cross-tenant read in this codebase, sibling to
 * `ClientDirectory` and audited the same way: `CrossTenantReaderInventoryArchTest`
 * pins the bypass inventory under this directory to exactly these two files.
 *
 * THREE STATEMENTS, merged in PHP — never a single joined query. Organizations
 * drive; the two aggregates are `keyBy('organization_id')` lookups that
 * default to zero/null. Doing the join in SQL across two child tables
 * (`projects` and `participants`) multiplies every project row by every
 * participant row — an org with 3 projects and 5 candidates would report 15.
 * A correlated per-organization loop avoids the fan-out but re-introduces the
 * N+1 shape this class exists to prevent; two grouped statements are correct
 * AND constant in the organization count.
 *
 * Scope, per statement, and why it differs:
 *   - `Organization` has no tenant scope at all (`Organization extends Model`,
 *     not `TenantModel`) and no soft deletes — the plural `withoutGlobalScopes()`
 *     is harmless there and matches `ClientDirectory::all()`'s ordering.
 *   - `Participant` carries no `TenantScoped` trait at all (plain `Model`, by
 *     its own design — see `Participant`'s docblock) and no soft deletes.
 *     Stripping the named `tenant` scope here is currently a no-op; it is
 *     written anyway, matching design D1's statement, to document intent and
 *     stay correct if that ever changes.
 *   - `Project` has BOTH a tenant scope (stripped) AND `SoftDeletes`. The
 *     PLURAL form here would silently count archived/deleted projects — this
 *     class strips ONLY the named `tenant` scope, so `SoftDeletingScope`
 *     keeps doing its job.
 */
final class ClientOverviewReader
{
    /**
     * @return list<array{
     *     id: int, name: string, created_at: string|null,
     *     projects: int, candidates: int, completed: int, errored: int,
     *     last_activity_at: string|null,
     * }>
     */
    public function all(): array
    {
        $organizations = Organization::withoutGlobalScopes()
            ->orderBy('name')
            ->get(['id', 'name', 'created_at']);

        /** @var Collection<int, object{organization_id: int, candidates: int, completed: int, errored: int, last_activity_at: string|null}> $participantStats */
        $participantStats = Participant::withoutGlobalScope('tenant')
            ->selectRaw(
                "organization_id,
                count(*) as candidates,
                count(*) filter (where status = 'completato') as completed,
                count(*) filter (where status = 'errore') as errored,
                max(updated_at) as last_activity_at"
            )
            ->groupBy('organization_id')
            ->get()
            ->keyBy('organization_id');

        /** @var Collection<int, object{organization_id: int, projects: int}> $projectStats */
        $projectStats = Project::withoutGlobalScope('tenant')
            ->selectRaw('organization_id, count(*) as projects')
            ->groupBy('organization_id')
            ->get()
            ->keyBy('organization_id');

        /** @var list<array{id: int, name: string, created_at: string|null, projects: int, candidates: int, completed: int, errored: int, last_activity_at: string|null}> $rows */
        $rows = $organizations
            ->map(function (Organization $organization) use ($participantStats, $projectStats): array {
                $participants = $participantStats->get($organization->id);
                $projects = $projectStats->get($organization->id);

                return [
                    'id' => (int) $organization->id,
                    'name' => $organization->name,
                    'created_at' => $organization->created_at?->toJSON(),
                    'projects' => $projects === null ? 0 : (int) $projects->projects,
                    'candidates' => $participants === null ? 0 : (int) $participants->candidates,
                    'completed' => $participants === null ? 0 : (int) $participants->completed,
                    'errored' => $participants === null ? 0 : (int) $participants->errored,
                    // Through Carbon, exactly like `created_at` five lines up.
                    //
                    // `created_at` is an Eloquent date attribute and serializes
                    // as ISO-8601 UTC; `last_activity_at` is a `selectRaw`
                    // alias, so no cast applies and PDO's raw
                    // "2026-09-07 12:44:43" reached the wire — 19 characters,
                    // NO timezone marker. Two fields in the same row, declared
                    // the same type, in two formats: `new Date()` reads the
                    // first as local time and the second as UTC, so the column
                    // was an hour off for a reader in CET and two in summer.
                    // Nothing breaks; the number is just wrong, and it shifts
                    // at DST so it reads as intermittent.
                    'last_activity_at' => $participants?->last_activity_at === null
                        ? null
                        : Carbon::parse($participants->last_activity_at)->toJSON(),
                ];
            })
            ->values()
            ->all();

        return $rows;
    }
}
