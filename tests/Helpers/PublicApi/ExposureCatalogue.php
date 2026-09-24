<?php

declare(strict_types=1);

namespace Tests\Helpers\PublicApi;

/**
 * Frozen "everything the admin sees" exclusion/addition catalogue for
 * `T-EXPOSE-001` (public-api step 4, SPEC.md §3.4).
 *
 * Field names use dot notation for a nested object and a `[]` suffix ONLY
 * for a list of nested OBJECTS (e.g. `competencies[].code`) — a list of
 * scalars (`default_webhook_events`, `allowed_domains`) keeps its bare
 * name, matching how SPEC.md itself names these fields.
 *
 * Hand-maintained, not computed: computing the diff live would make this
 * test tautological (it would always pass, since it would always compare
 * the current admin surface against itself). Any NEW admin field — on
 * either `Admin\OrganizationResource`, `ProjectResource` or
 * `AvatarTemplateResource` — breaks `ExposureTest` until it is explicitly
 * classified here as an exclusion (never public) or reconciled as an
 * addition (a genuinely public-only field, which is rare — most new admin
 * fields should stay admin-only, i.e. become a new EXCLUSION entry).
 *
 * Later SDD steps extend these arrays as new public resources
 * (`Interview`, `Scoring`, `WebhookDelivery`, `Export`, usage) land.
 */
final class ExposureCatalogue
{
    /**
     * @return array<string, list<string>>
     */
    public static function exclusions(): array
    {
        return [
            'Organization' => [
                'slug',
                'primary_color',
                'logo_url',
                'default_webhook_url',
                'default_webhook_events',
                'has_default_webhook_secret',
                'updated_at',
            ],
            'Project' => [
                'avatar_template_id',
                'avatar_template.id',
                'avatar_template.name',
                'avatar_template.description',
                'avatar_template.provider',
                'avatar_template.config.persona_id',
                'avatar_template.is_active',
                'avatar_template.created_at',
                'avatar_template.updated_at',
                'avatar_template.llm_model_id',
                'avatar_template.llm_credential_id',
                'avatar_template.llm_sync_status',
                'avatar_template.llm_synced_at',
                'avatar_template.llm.estimated_cost_usd_per_interview',
                'can.update',
                'can.delete',
                'competencies[].id',
                'competencies[].position',
                'deadline_at',
                'error_redirect_url',
                'framework_version_id',
                'goes_live_at',
                'has_webhook_secret',
                'organization_id',
                'pin_context.id',
                'pin_context.version',
                'pin_context.label',
                'pin_context.is_locked',
                'webhook_events',
                'webhook_url',
            ],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function additions(): array
    {
        return [
            'Organization' => [
                'mode',
                'allowed_domains',
            ],
            'Project' => [
                'avatar_display_name',
                'framework_version.version',
                'framework_version.label',
                'competencies[].name',
            ],
        ];
    }

    /**
     * Flattens a nested response array into dot-notation leaf paths — see
     * this class's own docblock for the `[]` convention.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public static function flattenKeys(array $data, string $prefix = ''): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $keys = array_merge($keys, self::flattenBranch($value, $path));

                continue;
            }

            $keys[] = $path;
        }

        return $keys;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<string>
     */
    private static function flattenBranch(array $value, string $path): array
    {
        if (! array_is_list($value)) {
            return self::flattenKeys($value, $path);
        }

        $first = $value[0] ?? null;

        if (is_array($first)) {
            return self::flattenKeys($first, $path.'[]');
        }

        // A list of scalars (or an empty list) — one leaf, bare name.
        return [$path];
    }
}
