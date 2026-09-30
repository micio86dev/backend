<?php

declare(strict_types=1);

namespace App\Actions\AvatarTemplates;

use App\Models\AvatarTemplate;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;

/**
 * Copies one avatar template into other organizations (avatar-template-duplicate).
 *
 * Every copy is a NEW row, created under `TenantContextScope::runFor(target)`
 * so that TenantScoped stamps the TARGET organization. `organization_id` is
 * not fillable and is never taken from a payload; no global scope is bypassed
 * to write. The same `saving` guards a normal create runs (the LLM binding
 * invariants) therefore run on every copy.
 *
 * What is copied: name, description, provider, config, persona and the
 * model/credential binding (credentials are platform rows). What is NOT:
 * `is_active` (a copy is inactive), and everything that belongs to the SOURCE's
 * provider-side resources (`heygen_llm_configuration_id`, `llm_sync_status`,
 * `llm_synced_at`) — the copy has its own lifecycle and syncs on its own.
 *
 * All-or-nothing: one transaction across every target. Audit rows are written
 * AFTER it commits, so a failed audit write can neither abort the copies nor
 * be recorded for copies that were rolled back. `AuditRecorder::record()` never
 * throws (it logs and swallows its own failure), so no guard is needed here.
 */
final class DuplicateAvatarTemplate
{
    private const NAME_MAX = 120;

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  list<int>  $targetOrganizationIds
     * @return list<array{organization_id: int, id: int, name: string}>
     */
    public function run(AvatarTemplate $source, array $targetOrganizationIds, ?string $name = null): array
    {
        $baseName = $name ?? $source->name;

        /** @var list<array{organization_id: int, id: int, name: string}> $created */
        $created = DB::transaction(function () use ($source, $targetOrganizationIds, $baseName): array {
            $created = [];

            foreach ($targetOrganizationIds as $targetId) {
                $created[] = TenantContextScope::runFor($targetId, function () use ($source, $baseName, $targetId): array {
                    $copy = AvatarTemplate::create([
                        'name' => $this->freeName($baseName),
                        'description' => $source->description,
                        'provider' => $source->provider,
                        'config' => $source->config,
                        'persona' => $source->persona,
                        'is_active' => false,
                        'llm_model_id' => $source->llm_model_id,
                        'llm_credential_id' => $source->llm_credential_id,
                    ]);

                    return ['organization_id' => $targetId, 'id' => $copy->id, 'name' => $copy->name];
                });
            }

            return $created;
        });

        foreach ($created as $row) {
            TenantContextScope::runFor($row['organization_id'], function () use ($row, $source): void {
                // Identifiers and the name only — never the config, which
                // carries provider-side ids closer to credentials than settings.
                $this->audit->record(
                    'avatar_template.duplicated',
                    'avatar_template',
                    $row['id'],
                    after: [
                        'name' => $row['name'],
                        'provider' => $source->provider,
                        'source_template_id' => $source->id,
                        'source_organization_id' => $source->organization_id,
                    ],
                );
            });
        }

        return $created;
    }

    /**
     * Names are unique per organization (partial unique index over live rows),
     * so a collision would surface as a 500. Never overwrite: suffix instead.
     * Must run inside the TARGET's tenant context, where the scope applies.
     */
    private function freeName(string $base): string
    {
        $candidate = $base;
        $n = 1;

        while (AvatarTemplate::where('name', $candidate)->exists()) {
            $suffix = $n === 1 ? ' (copy)' : " (copy {$n})";
            $candidate = mb_substr($base, 0, self::NAME_MAX - mb_strlen($suffix)).$suffix;
            $n++;
        }

        return $candidate;
    }
}
