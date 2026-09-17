<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalogue;

use App\Models\FrameworkCatalogRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CatalogueRevisionResource (framework-catalogue-authoring PR3, D12).
 *
 * `editable` is true only for a draft: a published revision is immutable,
 * and the backoffice renders its rows read-only rather than inferring that
 * from `state` on its own.
 *
 * @mixin FrameworkCatalogRevision
 */
class CatalogueRevisionResource extends JsonResource
{
    /**
     * @return array{id: int, state: string, is_baseline: bool, label: string|null, published_at: string|null, parent_revision_id: int|null, editable: bool}
     *
     * @scramble-return array{id: int, state: string, is_baseline: bool, label: string|null, published_at: string|null, parent_revision_id: int|null, editable: bool}
     */
    public function toArray(Request $request): array
    {
        /** @var FrameworkCatalogRevision $revision */
        $revision = $this->resource;

        return [
            'id' => (int) $revision->id,
            'state' => $revision->state,
            'is_baseline' => (bool) $revision->is_baseline,
            'label' => $revision->label,
            'published_at' => $revision->published_at?->toIso8601String(),
            'parent_revision_id' => $revision->parent_revision_id === null ? null : (int) $revision->parent_revision_id,
            'editable' => $revision->state === 'draft',
        ];
    }
}
