<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Actions\ConversationLlm\ResyncTemplateBinding;
use App\Models\AvatarTemplate;
use App\Models\User;
use App\Rules\PersonaDisplayName;
use App\Support\AvatarTemplates\ConfigValidator;
use App\Support\AvatarTemplates\TemplateReferenceValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The write-side validation and provider sync used by BOTH avatar-template
 * controllers: `AvatarTemplateController` (organization templates) and
 * `Api\PlatformAvatarTemplateController` (platform templates)
 * (global-avatar-templates A3).
 *
 * Extracted so the two surfaces cannot drift apart on what a valid template
 * is. What stays per controller is everything that depends on WHOSE template
 * it is: authorization, the row lookup, and the audit trail.
 */
trait ValidatesAvatarTemplateWrites
{
    /**
     * The seconds a good answer lasts (answer-time-range). The bounds live here and in the DB CHECK;
     * `min < max` needs both values, so it is `assertAnswerRangeOrdered()`'s job, not a rule's.
     *
     * @var array<string, list<string>>
     */
    private const ANSWER_RANGE_RULES = [
        'answer_min_seconds' => ['sometimes', 'integer', 'min:10', 'max:600'],
        'answer_max_seconds' => ['sometimes', 'integer', 'min:10', 'max:600'],
    ];

    /**
     * Refuses a range whose minimum is not below its maximum, comparing a field the request omits
     * against the stored value (the defaults on create). A one-field PATCH could otherwise
     * invert a range the other field's stored value defines.
     *
     * @param  array<string, mixed>  $validated
     */
    private function assertAnswerRangeOrdered(array $validated, int $storedMin = 90, int $storedMax = 150): void
    {
        $min = $validated['answer_min_seconds'] ?? $storedMin;
        $max = $validated['answer_max_seconds'] ?? $storedMax;

        if ($min >= $max) {
            throw ValidationException::withMessages([
                'answer_max_seconds' => 'The maximum answer time must be greater than the minimum.',
            ]);
        }
    }

    /**
     * @return array<string, list<string|ValidationRule|Closure>>
     */
    private function templateStoreRules(): array
    {
        // Both-or-neither is enforced by the DB CHECK (I1) and by
        // AvatarTemplate::booted()'s I2/I3/I4 guards — never re-checked
        // here (pluggable-conversation-llm PR P3a, design D4).
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            // Literal list — see catalogue()'s validation for why implode(self::PROVIDERS) is not used.
            'provider' => ['required', 'string', 'in:heygen,tavus'],
            'config' => ['required', 'array'],
            // Bind a language model to the template. `llm_model_id` and `llm_credential_id` are both set or both null.
            'llm_model_id' => ['sometimes', 'nullable', 'integer'],
            'llm_credential_id' => ['sometimes', 'nullable', 'integer'],
            ...self::ANSWER_RANGE_RULES,
            ...$this->personaRules(),
        ];
    }

    /**
     * @return array<string, list<string|ValidationRule|Closure>>
     */
    private function templateUpdateRules(): array
    {
        // Both-or-neither is enforced by the DB CHECK (I1) and by
        // AvatarTemplate::booted()'s I2/I3/I4 guards — never re-checked
        // here (pluggable-conversation-llm PR P3a, design D4). Both null
        // clears the binding (see "Unbinding a template clears only
        // that template's binding").
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'config' => ['sometimes', 'array'],
            // Bind a language model to the template. `llm_model_id` and `llm_credential_id` are both set or both null; sending both as null clears the binding.
            'llm_model_id' => ['sometimes', 'nullable', 'integer'],
            'llm_credential_id' => ['sometimes', 'nullable', 'integer'],
            ...self::ANSWER_RANGE_RULES,
            ...$this->personaRules(),
        ];
    }

    /**
     * The interviewer's display name (live-interview-call-chrome D7). The closure admits no other
     * persona key, so the write cannot smuggle one in; keys an import brought in are kept by
     * `personaWrite()`, not by the payload. It is a closure and not `array:display_name` because
     * Scramble publishes every key of that rule as REQUIRED, which would make the generated client
     * refuse `persona: {}`, the payload that clears the name.
     *
     * @return array<string, list<string|ValidationRule|Closure>>
     */
    private function personaRules(): array
    {
        return [
            'persona' => ['sometimes', 'array', static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_array($value) && array_diff(array_keys($value), ['display_name']) !== []) {
                    $fail('The persona carries a key that is not accepted.');
                }
            }],
            'persona.display_name' => ['nullable', new PersonaDisplayName],
        ];
    }

    /**
     * The `persona` a write leaves behind, as the attribute to merge into the validated payload (empty
     * when the request carries no `persona`, which leaves the column untouched).
     *
     * A write that carries a `persona` changes ONLY `display_name`: every other key already stored (an
     * import's) is kept. A blank or absent `display_name` UNSETS the key (never a stored null or empty
     * string), and the column becomes null when nothing is left.
     *
     * Read from the request, not from `validate()`'s result: Laravel drops an EMPTY array from the
     * validated data, and `persona: {}` is the payload that clears the name. The name itself was
     * validated by `PersonaDisplayName` before this runs.
     *
     * @param  array<string, mixed>|null  $current  the persona already stored
     * @return array{persona?: array<string, mixed>|null}
     */
    private function personaWrite(Request $request, ?array $current): array
    {
        if (! $request->has('persona')) {
            return [];
        }

        $persona = $current ?? [];
        $name = $request->input('persona.display_name');

        if (is_string($name) && trim($name) !== '') {
            $persona['display_name'] = trim($name);
        } else {
            unset($persona['display_name']);
        }

        return ['persona' => $persona === [] ? null : $persona];
    }

    /**
     * Whether the caller may set the superadmin-only fields (the external HeyGen
     * voice). Read from the authenticated user, never from the page or route: the
     * same rule on the platform and the organization template routes.
     */
    private function callerIsSuperadmin(): bool
    {
        $user = request()->user();

        return $user instanceof User && $user->is_superadmin === true;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assertConfigValid(string $provider, array $config): void
    {
        $errors = ConfigValidator::validate($provider, $config, $this->callerIsSuperadmin());

        // References are checked only once the shape is sound: a missing or
        // mistyped id would otherwise be reported twice.
        if ($errors === []) {
            $errors = TemplateReferenceValidator::validate($provider, $config);
        }

        if ($errors === []) {
            return;
        }

        // Every problem at once, one entry per offending knob
        // (generated-client-truth-and-session-safety D6) — `config.{key}` is
        // Laravel's own nested-attribute convention (`competency_ids.0`), and
        // the backoffice form maps each one onto its own control through the
        // shared 422-mapping pattern. `config` and `config.{knob}` are
        // disjoint by construction: this method only runs after
        // `$request->validate(['config' => ['required','array']])` already
        // passed, so a non-array config never reaches here.
        throw ValidationException::withMessages(
            collect($errors)
                ->mapWithKeys(fn (array $e): array => ["config.{$e['key']}" => $e['code']])
                ->all()
        );
    }

    /**
     * Names are unique PER SCOPE: the caller passes the query of the scope it
     * writes into (`AvatarTemplate::query()` for an organization, whose strict
     * scope never sees a platform row; `AvatarTemplate::platformOnly()` for the
     * platform), so an organization template and a platform one may share a name.
     *
     * @param  Builder<AvatarTemplate>  $sameScope
     */
    private function assertNameFreeAmong(Builder $sameScope, string $name, ?int $exceptId): void
    {
        $query = $sameScope->where('name', $name);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if (! $query->exists()) {
            return;
        }

        // Checked here so the unique index does not surface as a QueryException
        // → 500. A name collision is something the operator can fix, so it has
        // to read like one.
        throw $this->nameTaken();
    }

    /**
     * The pre-check above and the write are two statements, so two writers can
     * both pass the check; the partial unique index over live platform names is
     * the real guard and refuses the loser. That refusal is the same problem
     * the pre-check reports, so it is answered the same way: a 422 on `name`,
     * not a 500. Only THIS index is translated — any other unique violation is
     * a genuine defect and keeps propagating.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function answeringPlatformNameRace(Closure $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'avatar_templates_global_name_unique')) {
                throw $e;
            }

            throw $this->nameTaken();
        }
    }

    /**
     * `assertAnswerRangeOrdered()` reads the stored range before the write, so two concurrent
     * one-field PATCHes can each pass it and the second then meets the other's value. The CHECK
     * `avatar_templates_answer_range` is the real guard and refuses the loser; that is the same
     * problem the helper reports, so it is answered the same way (a 422, not a 500). Only THAT
     * constraint is translated.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function answeringAnswerRangeRace(Closure $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $e) {
            if ($e->getCode() !== '23514' || ! str_contains($e->getMessage(), 'avatar_templates_answer_range')) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'answer_max_seconds' => 'The maximum answer time must be greater than the minimum.',
            ]);
        }
    }

    private function nameTaken(): ValidationException
    {
        return ValidationException::withMessages([
            'name' => 'A template with this name already exists.',
        ]);
    }

    /**
     * Push persona-level knobs and the managed-mode LLM binding to the
     * template's provider, report it if that did not work, and PERSIST the
     * outcome (pluggable-conversation-llm PR P4/P5, design D0/D7/D8).
     *
     * Nine of the seventeen Tavus fields live on the PERSONA, not the
     * conversation — sent on a conversation they do nothing at all. Offering
     * them without this call would be the dead-knob defect this change refused
     * to port, nine times over.
     *
     * The result is ADDITIONAL data on a successful response, never an error.
     * The operator's intent is already recorded in our own database and saving
     * again retries; failing the save would discard a valid edit because a
     * third party was slow. But it is reported, because an operator who is not
     * told will believe the setting took effect.
     *
     * The provider dispatch AND the `llm_sync_status` stamp live in
     * `ResyncTemplateBinding`, reached by every path that re-pushes a binding
     * (credential rotation included). This method keeps the one thing that is
     * genuinely controller business: turning the result into the response's
     * `warning` key. It deliberately never resolves a full `LlmBinding` — a
     * controller is exactly the class that must never hold the plaintext key
     * (design D6, `LlmBindingContainmentArchTest`).
     *
     * @return array<string, mixed>
     */
    private function recordSync(?AvatarTemplate $template): array
    {
        if ($template === null) {
            return [];
        }

        $result = app(ResyncTemplateBinding::class)->run($template);

        return $result['status'] === 'warning'
            ? ['warning' => $result['message'] ?? 'pal_sync_failed']
            : [];
    }
}
