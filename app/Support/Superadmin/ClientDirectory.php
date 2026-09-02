<?php

declare(strict_types=1);

namespace App\Support\Superadmin;

use App\Models\Organization;

/**
 * Every client, for the superadmin's switcher.
 *
 * THE ONE DELIBERATE CROSS-TENANT READ, and it lives here rather than in the
 * controller because `AdminTenancySafetyArchTest` forbids
 * `withoutGlobalScopes(` anywhere under `app/Http/` — a rule worth keeping
 * exactly as it is: an unscoped query in the HTTP layer is the shape a
 * cross-tenant leak takes, and one named class is far easier to audit than
 * every controller.
 *
 * Why the superadmin's own bypass is not enough: a superadmin ACTING as one
 * client has bypass switched OFF and is scoped to that client, so a scoped
 * query would return the single organization they are already looking at — and
 * the switcher could never move them anywhere else. The list has to be
 * complete regardless of the current selection, which is exactly why the
 * unscoped read is deliberate here and nowhere else.
 *
 * IDENTITY ONLY. `id` and `name`, nothing more: the switcher needs a label for
 * a menu, and every other read stays behind whichever client is then selected.
 * Widening this return is how a menu becomes a cross-tenant data surface.
 */
final class ClientDirectory
{
    /**
     * @return list<array{id: int, name: string}>
     */
    public function all(): array
    {
        $rows = Organization::withoutGlobalScopes()
            ->orderBy('name')
            ->get(['id', 'name']);

        /** @var list<array{id: int, name: string}> $clients */
        $clients = $rows
            ->map(static fn (Organization $o): array => [
                'id' => (int) $o->id,
                'name' => $o->name,
            ])
            ->values()
            ->all();

        return $clients;
    }
}
