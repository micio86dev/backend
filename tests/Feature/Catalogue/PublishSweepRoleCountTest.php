<?php

declare(strict_types=1);

/**
 * gga review finding (blocking) on `CatalogueRules::MAX_ROLES`: its own
 * docblock claimed the closed-set rule lives at "the FormRequest and
 * publish-sweep layers", but `PublishRevision::violations()` never counted
 * roles at all — a 6th role inserted by any writer bypassing
 * `StoreRoleRequest` (a raw `DB::table()` insert, a future non-HTTP entry
 * point) published cleanly with no violation raised.
 */

use App\Actions\Catalogue\PublishRevision;
use App\Models\FrameworkCatalogRevision;
use App\Support\Catalogue\CatalogueRules;
use Illuminate\Support\Facades\DB;

function publishSweepRoleCountInsertRole(int $revisionId, string $code): void
{
    DB::table('framework_roles')->insert([
        'revision_id' => $revisionId, 'code' => $code,
        'name' => json_encode(['en' => $code]), 'responsibilities' => json_encode(['en' => $code]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('a 6th role, inserted directly, refuses publish', function (): void {
    $revision = FrameworkCatalogRevision::factory()->draft()->create();

    foreach (range(1, CatalogueRules::MAX_ROLES + 1) as $i) {
        publishSweepRoleCountInsertRole($revision->id, "ROLE{$i}");
    }

    $violations = app(PublishRevision::class)->violations($revision->fresh());

    $rules = array_column($violations, 'rule');
    $subjects = array_column($violations, 'subject');

    expect($rules)->toContain('roles_closed_set');
    expect($subjects)->toContain("revision:{$revision->id}");
});

test('exactly the max number of roles contributes no roles_closed_set violation', function (): void {
    $revision = FrameworkCatalogRevision::factory()->draft()->create();

    foreach (range(1, CatalogueRules::MAX_ROLES) as $i) {
        publishSweepRoleCountInsertRole($revision->id, "OKROLE{$i}");
    }

    $violations = app(PublishRevision::class)->violations($revision->fresh());

    expect(array_column($violations, 'rule'))->not->toContain('roles_closed_set');
});
