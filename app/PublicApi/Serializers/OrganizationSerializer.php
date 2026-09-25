<?php

declare(strict_types=1);

namespace App\PublicApi\Serializers;

use App\Enums\ApiKeyMode;
use App\Models\Organization;
use App\Support\PublicApi\PublicId;

/**
 * Public-safe `Organization` shape for the BEAI Public API (`/v1`) and,
 * later, the bulk export (public-api step 4/8 both share this class —
 * SPEC.md §3.4 "Implement one serializer per resource shared by the public
 * API and the export").
 *
 * `openapi.yaml`'s `Organization` schema declares `default_language` as an
 * OPTIONAL property (`?` in SPEC.md §3.3's own row) and it is omitted here
 * entirely: `organizations` has no language/locale column at all — nothing
 * to read, so nothing is emitted, rather than a fabricated `null`.
 *
 * Framework-light by design (no HTTP concerns, no `Request`/`JsonResource`
 * dependency) so the export step can call `toArray()` directly outside any
 * HTTP request lifecycle.
 */
final class OrganizationSerializer
{
    /**
     * @return array{id: string, name: string, mode: 'live'|'test', allowed_domains: list<string>, created_at: string}
     */
    public static function toArray(Organization $organization, ApiKeyMode $mode): array
    {
        return [
            'id' => PublicId::encode($organization),
            'name' => $organization->name,
            // "mode (of the key)" — SPEC.md §3.3 — the AUTHENTICATED KEY's
            // mode, never an organization column (there isn't one): the
            // same organization answers `live` through a live key and
            // `test` through a test key.
            'mode' => $mode->value,
            'allowed_domains' => $organization->allowed_domains ?? [],
            'created_at' => (string) $organization->created_at?->toISOString(),
        ];
    }
}
