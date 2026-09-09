<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Competency;
use App\Models\Role;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The composition invariants of a project: which role, which competencies.
 *
 * Shared by `StoreProjectRequest` and `UpdateProjectRequest` because they are
 * the same invariants, and only one of the two was enforcing them. POST
 * checked `role_code` against the five roles and every competency against the
 * role's pivot; PATCH checked `role_code` as `['nullable', 'string']` and the
 * competencies not at all — so on a DRAFT (where the immutability guard does
 * not apply) an operator could PATCH `role_code: "NOT_A_ROLE"` and get a 200.
 *
 * The damage surfaced far away and much later: `InterviewController` looks the
 * role up by code, finds nothing, and the interview fails to compose — for a
 * project the API said was fine. The door was guarded and the window was not.
 *
 * `$roleCode` is a parameter rather than read from the request, because on a
 * PATCH the value that matters is "submitted, or the one already stored", and
 * only the caller knows which.
 */
trait ValidatesProjectComposition
{
    /**
     * The `avatar_template_id` rule, for both requests.
     *
     * REQUIRED on create. It shipped nullable with the organization's active
     * template as a fallback, and the fallback is exactly what let the
     * CONFIGURATION choose silently instead of the project — the defect the
     * column was added to fix. An organization that owns no template
     * therefore cannot create a project until it has one: deliberate, and
     * surfaced here rather than as an interview running on something nobody
     * chose.
     *
     * `sometimes` WITHOUT `nullable` on update, and the pairing is the whole
     * rule: `sometimes` keeps a PATCH that does not mention the field from
     * touching it, while dropping `nullable` makes an explicit null a
     * validation error rather than an unpin. There is nothing left to unpin
     * TO.
     *
     * Org-scoped, like `framework_version_id`: a foreign template must be
     * refused HERE, not merely ignored by `ActiveTemplateResolver` later —
     * ignoring it still leaves a cross-tenant id persisted in our row. And
     * `->whereNull('deleted_at')`, because `Rule::exists` is a raw
     * query-builder rule that does NOT apply the model's SoftDeletes scope:
     * an unused template deletes fine, and pinning a trashed one reinstates
     * the silent-fallback defect above.
     *
     * In the trait because it was copied into both classes, prose and all,
     * and duplicated comments drift exactly like duplicated rules do.
     *
     * @return list<mixed>
     *
     * $orgId is nullable because `User::$organization_id` is: a user with no
     * organization can hold no templates, so the rule matches nothing and the
     * field is refused — which is the correct answer, not a special case.
     */
    private function avatarTemplateRule(?int $orgId, string $presence): array
    {
        return [
            $presence,
            'integer',
            Rule::exists('avatar_templates', 'id')
                ->where('organization_id', $orgId)
                ->whereNull('deleted_at'),
        ];
    }

    /**
     * The catalogue check, hoisted ABOVE the caller's "basic rules passed"
     * gate.
     *
     * It has to run there. When MTG/LAT are not seeded, `competency_ids.*`'s
     * `exists` rule fails first, the gate returns, and the operator is told
     * "the selected competency_ids is invalid" — about a catalogue the
     * platform never loaded. POTENTIAL_CATALOG_INCOMPLETE is the one answer
     * that says what is actually wrong, and it is structured so it can be
     * acted on.
     *
     * `StoreProjectRequest` hoisted it inline and `UpdateProjectRequest` did
     * not, so the same input answered two different ways depending on the
     * verb — the exact divergence this trait exists to end. Returns true when
     * it fired, so the caller can stop.
     */
    private function guardPotentialCatalog(Validator $v, mixed $type): bool
    {
        // `mixed`, and the strict comparison below is what makes it safe.
        // This runs ABOVE the errors gate, so `assessment_type` has not been
        // validated yet — a typed `?string` parameter turned
        // `assessment_type: ["potential"]` into a TypeError under
        // `strict_types`, which is a 500 where the `string`/`in` rules would
        // have answered `assessment_type_invalid`. `!==` against a string
        // answers false for an array or an int without touching them.
        if ($type !== 'potential') {
            return false;
        }

        if (Competency::whereIn('code', ['MTG', 'LAT'])->count() >= 2) {
            return false;
        }

        $v->errors()->add('__potential_catalog__', 'POTENTIAL_CATALOG_INCOMPLETE');

        return true;
    }

    /**
     * @param  array<int, int>  $competencyIds
     */
    private function validateComposition(
        Validator $v,
        ?string $type,
        ?string $roleCode,
        array $competencyIds
    ): void {
        if ($type === 'potential') {
            $this->validatePotential($v, $roleCode, $competencyIds);
        } elseif ($type === 'standard') {
            $this->validateStandard($v, $roleCode, $competencyIds);
        }
    }

    /**
     * Validate potential assessment_type invariants.
     *
     * Order: POTENTIAL_CATALOG_INCOMPLETE FIRST (in `guardPotentialCatalog`,
     * hoisted above the caller's errors gate), then role_code, then the
     * subset check. The role check used to run first and return, so a PATCH
     * to `potential` on an unseeded catalogue answered about `role_code` and
     * never produced the structured response that says the platform is
     * missing MTG/LAT — while this docblock asserted the opposite order.
     *
     * @param  array<int, int>  $competencyIds
     */
    private function validatePotential(Validator $v, ?string $roleCode, array $competencyIds): void
    {
        // The catalogue check already ran, hoisted above the caller's errors
        // gate — see `guardPotentialCatalog`. Reaching here means it passed.
        //
        // role_code must be null for potential. A CODE: this names no
        //    competency and no role, so it carries nothing a code does not —
        //    the same class as `framework_version_immutable`.
        if ($roleCode !== null) {
            $v->errors()->add('role_code', 'role_code_must_be_null');

            return;
        }

        // 3. Competencies must be ⊆ {MTG, LAT} and all type='potential'
        if (! empty($competencyIds)) {
            $competencies = Competency::whereIn('id', $competencyIds)->get();

            foreach ($competencies as $competency) {
                if ($competency->type !== 'potential') {
                    $v->errors()->add('competency_ids', "Competency '{$competency->code}' is type=standard; potential projects require only potential-type competencies.");

                    return;
                }
                if (! in_array($competency->code, ['MTG', 'LAT'], true)) {
                    $v->errors()->add('competency_ids', "Competency '{$competency->code}' is not in {MTG, LAT}.");

                    return;
                }
            }
        }
    }

    /**
     * Validate standard assessment_type invariants.
     *
     * @param  array<int, int>  $competencyIds
     */
    private function validateStandard(Validator $v, ?string $roleCode, array $competencyIds): void
    {
        $validRoles = ['ICO', 'FLL', 'MLL', 'BUL', 'SRX'];

        if (! in_array($roleCode, $validRoles, true)) {
            // A CODE. The five roles are a closed set the UI already offers;
            // listing them back in English tells an Italian operator nothing
            // their own role picker does not.
            $v->errors()->add('role_code', 'role_invalid');

            return;
        }

        if (! empty($competencyIds)) {
            // Validate each competency: must be type=standard and assigned to this role
            $role = Role::where('code', $roleCode)->first();
            if ($role === null) {
                $v->errors()->add('role_code', "Role '{$roleCode}' not found in catalog.");

                return;
            }

            $assignedIds = DB::table('framework_role_competency')
                ->where('role_id', $role->id)
                ->pluck('competency_id')
                ->toArray();

            $competencies = Competency::whereIn('id', $competencyIds)->get();

            foreach ($competencies as $competency) {
                if ($competency->type !== 'standard') {
                    $v->errors()->add('competency_ids', "Competency '{$competency->code}' is type=potential; standard projects require only standard-type competencies.");

                    return;
                }
                if (! in_array($competency->id, $assignedIds, true)) {
                    $v->errors()->add('competency_ids', "Competency '{$competency->code}' is not assigned to role '{$roleCode}'.");

                    return;
                }
            }
        }
    }

    /**
     * Translate the `__potential_catalog__` sentinel into the documented
     * response.
     *
     * It lives HERE, with the rule that raises it. The rule was extracted into
     * this trait and its translator was left behind in `StoreProjectRequest`,
     * so a PATCH flipping a draft to `potential` on an unseeded catalog
     * answered with the raw sentinel as a public field name — no `code`, and
     * unrecognisable to a client that already handles the POST contract.
     * One sentinel, one translator.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $errors = $validator->errors();

        if ($errors->has('__potential_catalog__')) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Potential catalog incomplete: MTG/LAT competencies are not seeded.',
                    'code' => 'POTENTIAL_CATALOG_INCOMPLETE',
                ], 422)
            );
        }

        parent::failedValidation($validator);
    }

    /**
     * Machine codes for every SHAPE rule, shared by both project requests.
     *
     * A response body is machine-facing: the app that renders it is the only
     * layer that knows the operator's language. Without this map an Italian
     * operator creating a project with a duplicate slug read "The name has
     * already been taken." under an Italian label.
     *
     * In the TRAIT, not copied into both classes, for the reason this trait
     * exists at all: the composition rules lived in two places and only one
     * of them was enforcing them. A duplicated map re-creates that exactly —
     * add a rule to one class tomorrow, map it there, and the other drifts
     * silently. The derived test cannot catch that either: it checks each
     * request's own rules against its own messages, so two drifted maps both
     * pass. `status.*` is here too, unused by the POST request, because an
     * unused key costs nothing and a missing one costs an English sentence.
     *
     * The COMPOSITION refusals are deliberately absent. They are authored
     * sentences naming the competency and the role that clash, and a code
     * would throw away the only part that tells the operator what to change.
     * The backoffice falls back to its own localized message for anything
     * that is not a code, so they never reach a screen verbatim either.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'framework_version_id.required' => 'framework_version_required',
            'framework_version_id.integer' => 'framework_version_invalid',
            'framework_version_id.exists' => 'framework_version_invalid',
            'framework_version_id.prohibited' => 'framework_version_immutable',
            'slug.required' => 'slug_required',
            'slug.string' => 'slug_invalid',
            'slug.max' => 'slug_too_long',
            'slug.regex' => 'slug_invalid',
            'slug.unique' => 'slug_taken',
            'name.required' => 'name_required',
            'name.string' => 'name_invalid',
            'name.max' => 'name_too_long',
            'assessment_type.required' => 'assessment_type_required',
            'assessment_type.string' => 'assessment_type_invalid',
            'assessment_type.in' => 'assessment_type_invalid',
            'role_code.string' => 'role_invalid',
            'language.required' => 'language_required',
            'language.string' => 'language_invalid',
            'language.in' => 'language_invalid',
            'competency_ids.array' => 'competencies_invalid',
            'competency_ids.list' => 'competencies_invalid',
            'competency_ids.*.integer' => 'competencies_invalid',
            'competency_ids.*.distinct' => 'competencies_duplicated',
            'competency_ids.*.exists' => 'competency_unknown',
            'pause_every_n_competencies.integer' => 'pause_every_n_invalid',
            'pause_every_n_competencies.min' => 'pause_every_n_invalid',
            'pause_every_n_competencies.max' => 'pause_every_n_invalid',
            'nudge_min_chars.integer' => 'nudge_min_chars_invalid',
            'nudge_min_chars.min' => 'nudge_min_chars_invalid',
            'nudge_min_chars.max' => 'nudge_min_chars_invalid',
            'exit_redirect_url.string' => 'exit_redirect_url_invalid',
            'exit_redirect_url.url' => 'exit_redirect_url_invalid',
            'exit_redirect_url.max' => 'exit_redirect_url_too_long',
            'error_redirect_url.string' => 'error_redirect_url_invalid',
            'error_redirect_url.url' => 'error_redirect_url_invalid',
            'error_redirect_url.max' => 'error_redirect_url_too_long',
            'webhook_url.string' => 'webhook_url_invalid',
            'webhook_url.url' => 'webhook_url_invalid',
            'webhook_url.max' => 'webhook_url_too_long',
            'avatar_template_id.required' => 'avatar_template_required',
            'avatar_template_id.integer' => 'avatar_template_invalid',
            'avatar_template_id.exists' => 'avatar_template_invalid',
            'webhook_secret.string' => 'webhook_secret_invalid',
            'webhook_secret.max' => 'webhook_secret_too_long',
            'webhook_events.array' => 'webhook_events_invalid',
            'webhook_events.*.in' => 'webhook_event_unknown',
            'deadline_at.date' => 'deadline_invalid',
            'goes_live_at.date' => 'goes_live_invalid',
            'status.string' => 'status_invalid',
            'status.in' => 'status_invalid',
        ];
    }
}
