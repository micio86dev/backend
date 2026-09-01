<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\AvatarTemplateResource;
use App\Models\AvatarTemplate;
use App\Models\Project;
use App\Services\ConversationLlm\HeygenLlmRegistrar;
use App\Support\Audit\AuditRecorder;
use App\Support\AvatarTemplates\ConfigValidator;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use App\Support\AvatarTemplates\TavusPalSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Avatar templates — org-scoped CRUD plus activation (C14 PR4).
 *
 * Admin only, enforced by AvatarTemplatePolicy. Tenancy is enforced by the
 * TenantScoped global scope rather than by checks here, so another tenant's row
 * is never found at all — a 404, not a 403. That distinction matters: a 403
 * confirms the id exists and turns this into an enumeration oracle.
 */
final class AvatarTemplateController extends Controller
{
    private const PROVIDERS = ['heygen', 'tavus'];

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AvatarTemplate::class);

        return AvatarTemplateResource::collection(
            AvatarTemplate::orderByDesc('is_active')->orderBy('name')->get()
        );
    }

    /**
     * The field specs both providers accept.
     *
     * Served rather than duplicated in the Nuxt app, so the form, the
     * validation and the provider payload cannot disagree — which is the whole
     * reason the spec is declarative. Machine-facing and NOT localized: it
     * carries label keys, and translation happens where the operator's locale
     * lives.
     */
    public function fieldSpecs(): JsonResponse
    {
        $this->authorize('viewAny', AvatarTemplate::class);

        $specs = [];

        foreach (self::PROVIDERS as $provider) {
            $specs[$provider] = array_map(
                fn ($field): array => $field->toArray(),
                ProviderFieldSpecs::for($provider),
            );
        }

        return response()->json(['data' => $specs]);
    }

    public function show(int $id): AvatarTemplateResource
    {
        $template = AvatarTemplate::findOrFail($id);
        $this->authorize('view', $template);

        return new AvatarTemplateResource($template);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AvatarTemplate::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'provider' => ['required', 'string', 'in:'.implode(',', self::PROVIDERS)],
            'config' => ['required', 'array'],
            // Both-or-neither is enforced by the DB CHECK (I1) and by
            // AvatarTemplate::booted()'s I2/I3/I4 guards — never re-checked
            // here (pluggable-conversation-llm PR P3a, design D4).
            'llm_model_id' => ['sometimes', 'nullable', 'integer'],
            'llm_credential_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $this->assertConfigValid($validated['provider'], $validated['config']);
        $this->assertNameFree($validated['name'], null);

        // is_active is deliberately absent from the accepted fields. Creating a
        // template must never change what candidates are seeing right now, and
        // if creation could activate it would also have to deactivate something
        // else — silently, as a side effect of a create.
        $template = AvatarTemplate::create($validated);

        app(AuditRecorder::class)->record(
            'avatar_template.created',
            'avatar_template',
            $template->id,
            after: ['name' => $template->name, 'provider' => $template->provider],
        );

        return (new AvatarTemplateResource($template))
            ->additional($this->recordSync($template))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, int $id): AvatarTemplateResource
    {
        $template = AvatarTemplate::findOrFail($id);
        $this->authorize('update', $template);

        $validated = $request->validate([
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
        ]);

        // The provider is immutable. Changing it would leave every knob in the
        // config belonging to the other one — avatarId where faceId is
        // expected, with nothing overlapping — so the template would validate
        // as empty and silently fall back to environment defaults. Making a new
        // template is one click and leaves an audit trail.
        if ($request->has('provider') && $request->input('provider') !== $template->provider) {
            throw ValidationException::withMessages([
                'provider' => 'The provider cannot be changed after creation. Create a new template instead.',
            ]);
        }

        if (array_key_exists('config', $validated)) {
            $this->assertConfigValid($template->provider, $validated['config']);
        }

        if (array_key_exists('name', $validated)) {
            $this->assertNameFree($validated['name'], $template->id);
        }

        // Names, never ids — the AuditRecorder doctrine applied to a binding
        // change (pluggable-conversation-llm PR P3a, design D3/D4). Captured
        // BEFORE the write, while the OLD binding (if any) is still resolvable.
        $bindingRequested = array_key_exists('llm_model_id', $validated)
            || array_key_exists('llm_credential_id', $validated);
        $beforeBindingNames = $bindingRequested ? $this->bindingAuditNames($template) : null;

        $before = ['name' => $template->name];
        $template->update($validated);

        app(AuditRecorder::class)->record(
            'avatar_template.updated',
            'avatar_template',
            $template->id,
            before: $before,
            after: ['name' => $template->name],
        );

        if ($bindingRequested) {
            $this->recordBindingChange($template->refresh(), $beforeBindingNames);
        }

        return (new AvatarTemplateResource($template))->additional($this->recordSync($template));
    }

    /**
     * @return array{model_key: string|null, credential_name: string|null}
     */
    private function bindingAuditNames(AvatarTemplate $template): array
    {
        return [
            'model_key' => $template->llmModel?->key,
            'credential_name' => $template->llmCredential?->name,
        ];
    }

    /**
     * Audits a binding change as `.llm_bound` or `.llm_unbound` — never
     * `.updated` (pluggable-conversation-llm PR P3a, design D3).
     *
     * The actual HeyGen `DELETE /v1/llm-configurations/{id}` call on unbind
     * happens in `recordSync()`, called right after this method returns —
     * it dispatches to `HeygenLlmRegistrar::ensureConfiguration()`, whose
     * own unbound branch calls `forget()` (PR P5, design D8). This method
     * therefore no longer clears `heygen_llm_configuration_id` itself; doing
     * so here AND there would be the exact duplication P5's own task list
     * warns against ("extend it, don't duplicate it").
     *
     * @param  array{model_key: string|null, credential_name: string|null}|null  $beforeBindingNames
     */
    private function recordBindingChange(AvatarTemplate $template, ?array $beforeBindingNames): void
    {
        $isNowUnbound = $template->llm_model_id === null && $template->llm_credential_id === null;

        if ($isNowUnbound) {
            app(AuditRecorder::class)->record(
                'avatar_template.llm_unbound',
                'avatar_template',
                $template->id,
                before: $beforeBindingNames,
            );

            return;
        }

        app(AuditRecorder::class)->record(
            'avatar_template.llm_bound',
            'avatar_template',
            $template->id,
            before: $beforeBindingNames,
            after: $this->bindingAuditNames($template),
        );
    }

    /**
     * Make this the organization's active template.
     *
     * The swap runs in ONE transaction, deactivate-then-activate. The order is
     * forced: the partial unique index refuses a second active row, so
     * activating first would simply fail. Doing it outside a transaction would
     * leave a window with no active template at all, during which an interview
     * starting would quietly fall back to the environment defaults — the exact
     * behaviour this whole change exists to replace.
     */
    public function activate(int $id): AvatarTemplateResource
    {
        $template = AvatarTemplate::findOrFail($id);
        $this->authorize('activate', $template);

        // Validated again HERE, not only at write time. A config goes stale
        // when the field spec changes, and a template saved under the old one
        // is still sitting in the table. Activation is the last moment anybody
        // can catch that before a candidate does.
        $this->assertConfigValid($template->provider, $template->config);

        DB::transaction(function () use ($template): void {
            // Narrowed to the SAME provider (pluggable-conversation-llm PR
            // P0, design D0). An organization may hold one active template
            // PER PROVIDER simultaneously — deactivating across every
            // provider would silently kill an unrelated, still-correct
            // Tavus template the moment an operator activates a HeyGen one.
            AvatarTemplate::where('is_active', true)
                ->where('provider', $template->provider)
                ->whereKeyNot($template->id)
                ->update(['is_active' => false]);

            $template->update(['is_active' => true]);
        });

        // Which face every candidate meets is exactly the kind of change an
        // auditor asks about after the fact.
        app(AuditRecorder::class)->record(
            'avatar_template.activated',
            'avatar_template',
            $template->id,
            after: ['name' => $template->name, 'provider' => $template->provider],
        );

        $fresh = $template->fresh();

        return (new AvatarTemplateResource($fresh))->additional($this->recordSync($fresh));
    }

    public function destroy(int $id): JsonResponse
    {
        $template = AvatarTemplate::findOrFail($id);
        $this->authorize('delete', $template);

        if ($template->is_active) {
            // Deleting what candidates are currently being interviewed with is
            // a decision, not a cleanup. 409 rather than 422: the request is
            // well-formed, the state is what refuses it.
            return response()->json([
                'error' => 'template_active',
                'message' => 'template_active',
            ], Response::HTTP_CONFLICT);
        }

        // `projects.avatar_template_id` is NOT NULL with `restrictOnDelete`, so
        // the database would refuse this anyway — as a raw foreign-key
        // violation, which reaches the operator as a 500 and tells them
        // nothing. Refusing here turns it into the same well-formed 409 the
        // active-template case already returns, and names the count so the
        // operator knows how much work reassigning it is before they start.
        $projectCount = Project::where('avatar_template_id', $template->id)->count();

        if ($projectCount > 0) {
            return response()->json([
                'error' => 'template_in_use',
                'message' => 'template_in_use',
                'project_count' => $projectCount,
            ], Response::HTTP_CONFLICT);
        }

        if ($template->provider === 'heygen') {
            // Never throws (design D8) — deleting OUR row must not be
            // blocked by an unreachable HeyGen account.
            app(HeygenLlmRegistrar::class)->forget($template);
        }

        app(AuditRecorder::class)->record(
            'avatar_template.deleted',
            'avatar_template',
            $template->id,
            before: ['name' => $template->name, 'provider' => $template->provider],
        );

        $template->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assertConfigValid(string $provider, array $config): void
    {
        $errors = ConfigValidator::validate($provider, $config);

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

    private function assertNameFree(string $name, ?int $exceptId): void
    {
        $query = AvatarTemplate::where('name', $name);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if (! $query->exists()) {
            return;
        }

        // Checked here so the unique index does not surface as a QueryException
        // → 500. A name collision is something the operator can fix, so it has
        // to read like one.
        throw ValidationException::withMessages([
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
     * `llm_sync_status`/`llm_synced_at` are written HERE for BOTH providers —
     * `TavusPalSync::sync()` returns a transient result and persists nothing
     * (C-E: `Support/AvatarTemplates/` stays pure of DB writes); `HeygenLlmRegistrar`
     * persists `heygen_llm_configuration_id` itself (it IS the orphan ledger,
     * design D8) but not these two columns — D0's resolver rule stays ONE
     * line for both providers, decided from these same two columns
     * regardless of which provider wrote the underlying vendor state.
     * Without this write, `degraded` (design D0) would be UNREACHABLE: a
     * template whose provider push failed would still resolve `applied` at a
     * later session-issue and get billed for a binding that never took
     * effect.
     *
     * Deliberately checks `llm_model_id`/`llm_credential_id` directly rather
     * than resolving a full `LlmBinding` here — a controller is exactly the
     * class an `LlmBinding` (which carries the plaintext key) must never
     * reach (design D6, `LlmBindingContainmentArchTest`), and a boolean
     * presence check is all this decision needs.
     *
     * @return array<string, mixed>
     */
    private function recordSync(?AvatarTemplate $template): array
    {
        if ($template === null) {
            return [];
        }

        $result = match ($template->provider) {
            'tavus' => app(TavusPalSync::class)->sync($template),
            'heygen' => app(HeygenLlmRegistrar::class)->ensureConfiguration($template),
            default => ['status' => 'skipped'],
        };

        if (in_array($template->provider, ['tavus', 'heygen'], true)) {
            $isBound = $template->llm_model_id !== null && $template->llm_credential_id !== null;

            // saveQuietly() — NOT save() — IS A RE-ENTRANCY GUARD, NOT A
            // STYLE CHOICE. A plain save() re-fires the `saving` event,
            // which re-runs AvatarTemplate::booted()'s I2/I3/I4 invariants
            // on a binding that already passed them for this same request,
            // and dispatches `saved` observers a second time for a write
            // that is bookkeeping ABOUT a sync, not a save a user made. Do
            // NOT "tidy" this to save() in a future refactor.
            $template->forceFill([
                'llm_sync_status' => $result['status'] === 'synced'
                    ? 'synced'
                    : ($isBound ? 'failed' : 'not_required'),
                'llm_synced_at' => $result['status'] === 'synced' ? now() : null,
            ])->saveQuietly();
        }

        return $result['status'] === 'warning'
            ? ['warning' => $result['message'] ?? 'pal_sync_failed']
            : [];
    }
}
