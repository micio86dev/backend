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

            // Interview (public-api step 5) — admin `Admin\ParticipantResource`
            // (list/summary) UNION `Admin\ParticipantDetailResource` (detail),
            // both flattened together, against `App\PublicApi\Serializers\
            // InterviewSerializer` — mirrors how the Project entry above merges
            // its own admin resource with its nested AvatarTemplateResource.
            // `id`/`project_id`/`candidate_ref`/`email`/`display_name`/
            // `role_code`/`language`/`status`/`started_at`/`completed_at`/
            // `created_at` are shared field NAMES on both sides (this catalogue
            // diffs PATHS, never value types — admin's `id` is a bigint,
            // public's is a `int_…` string, exactly like Project's own `id`)
            // and so are absent from both lists below.
            'Interview' => [
                // admin-only: a flat convenience field (list view) and the
                // full nested Project object (detail view) — the public
                // equivalent is `project_id` (a reference) plus, only under
                // `?expand=project`, the SAME `Project` schema T-EXPOSE-001
                // already proves for Project — never this flat/nested shape.
                'project_name',
                'project.id',
                'project.name',
                'project.status',
                'project.goes_live_at',
                'project.deadline_at',
                // admin-only: ParticipantDetailResource nests started_at/
                // completed_at/session_count under `timeline` IN ADDITION to
                // ParticipantResource's own bare `started_at`/`completed_at`
                // (which DO match the public shape and are excluded from
                // this list for exactly that reason).
                'timeline.started_at',
                'timeline.completed_at',
                'timeline.session_count',
                // admin-only aggregate progress/elapsed/cost — SPEC.md §3.4
                // "Per-provider cost breakdowns ... not exposed", and elapsed
                // time / done-of-total progress are backoffice operator
                // conveniences, not part of the binding Interview resource.
                'progress.done',
                'progress.total',
                'elapsed.seconds',
                'elapsed.sessions_counted',
                'elapsed.sessions_total',
                'cost.amount',
                'cost.currency',
                'cost.is_estimate',
                'cost.sessions_estimated',
                'cost.sessions_total',
                // admin-only download links (transcript/evaluation) — never
                // in the public serializer; the public API's own transcript/
                // scoring endpoints (later steps) are a SEPARATE surface.
                'files.transcript.type',
                'files.transcript.ref',
                'files.transcript.url',
                'files.evaluation_raw.type',
                'files.evaluation_raw.ref',
                'files.evaluation_raw.url',
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

            // Interview (public-api step 5) — see the matching comment in
            // exclusions() above for the comparison this pairs with.
            'Interview' => [
                'livemode',
                'metadata',
                'exit_redirect_url',
                'hosted_url',
                // The public `progress[]` shape genuinely differs from
                // admin's `progress.{done,total}` aggregate — see
                // exclusions() above; this is its own field name/path, not
                // a renamed version of the admin one.
                'progress[].competency_code',
                'progress[].answers',
                'transcript_ready',
                'scoring_ready',
                'recording_ready',
                // Neither admin resource exposes `updated_at` at all.
                'updated_at',
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
