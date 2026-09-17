<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalogue;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CatalogueRoleResource (framework-catalogue-authoring PR3, D12).
 *
 * Deliberately SEPARATE from `App\Http\Resources\RoleResource`: that one
 * resolves `name`/`responsibilities` to the CURRENT LOCALE (a single
 * string), correct for the candidate-facing read surface it serializes for
 * but wrong here — a superadmin authoring the catalogue needs the FULL
 * `{en, it}` locale map to edit both languages at once, not whichever one
 * the request happens to resolve to.
 *
 * @mixin Role
 */
class CatalogueRoleResource extends JsonResource
{
    /**
     * @return array{id: int, code: string, revision_id: int, name: array<string, string>, responsibilities: array<string, string>, competency_ids: list<int>}
     *
     * @scramble-return array{id: int, code: string, revision_id: int, name: array<string, string>, responsibilities: array<string, string>, competency_ids: list<int>}
     */
    public function toArray(Request $request): array
    {
        /** @var Role $role */
        $role = $this->resource;

        return [
            'id' => (int) $role->id,
            'code' => $role->code,
            'revision_id' => (int) $role->revision_id,
            'name' => $role->getTranslations('name'),
            'responsibilities' => $role->getTranslations('responsibilities'),
            // Ordered by pivot `position` (`Role::competencies()`'s own
            // relation definition) — framework-catalogue-authoring PR8b, so
            // the backoffice can render and re-PUT a role's competency set.
            // The RELATION property, not `competencies()->pluck(...)` (gga
            // review finding): the property reuses an eager-loaded
            // collection with zero extra queries when the caller preloaded
            // it (`RoleController::index()`), while a fresh query-builder
            // call issues one query per role regardless.
            'competency_ids' => array_map('intval', array_values($role->competencies->pluck('id')->all())),
        ];
    }
}
