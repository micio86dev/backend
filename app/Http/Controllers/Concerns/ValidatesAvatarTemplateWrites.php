<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Actions\ConversationLlm\ResyncTemplateBinding;
use App\Models\AvatarTemplate;
use App\Support\AvatarTemplates\ConfigValidator;
use App\Support\AvatarTemplates\TemplateReferenceValidator;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * @return array<string, list<string>>
     */
    private function templateStoreRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            // Literal list — see catalogue()'s validation for why implode(self::PROVIDERS) is not used.
            'provider' => ['required', 'string', 'in:heygen,tavus'],
            'config' => ['required', 'array'],
            // Both-or-neither is enforced by the DB CHECK (I1) and by
            // AvatarTemplate::booted()'s I2/I3/I4 guards — never re-checked
            // here (pluggable-conversation-llm PR P3a, design D4).
            'llm_model_id' => ['sometimes', 'nullable', 'integer'],
            'llm_credential_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function templateUpdateRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'config' => ['sometimes', 'array'],
            // Both-or-neither is enforced by the DB CHECK (I1) and by
            // AvatarTemplate::booted()'s I2/I3/I4 guards — never re-checked
            // here (pluggable-conversation-llm PR P3a, design D4). Both null
            // clears the binding (see "Unbinding a template clears only
            // that template's binding").
            'llm_model_id' => ['sometimes', 'nullable', 'integer'],
            'llm_credential_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assertConfigValid(string $provider, array $config): void
    {
        $errors = ConfigValidator::validate($provider, $config);

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
