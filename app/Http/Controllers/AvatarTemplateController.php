<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ValidatesAvatarTemplateWrites;
use App\Http\Resources\AvatarTemplateResource;
use App\Models\AvatarTemplate;
use App\Models\Project;
use App\Services\ConversationLlm\HeygenLlmRegistrar;
use App\Support\Audit\AuditRecorder;
use App\Support\AvatarTemplates\AvatarProviderCatalogue;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
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
    use ValidatesAvatarTemplateWrites;

    private const PROVIDERS = ['heygen', 'tavus'];

    /**
     * Which `resource` values are valid for each provider (avatar-template-
     * catalogue PR1, delta spec: "resource (voice | avatar for heygen;
     * voice | replica | pal for tavus)"). Coupled deliberately — `replica` is a
     * real resource value, just not for `heygen`, so validating provider and
     * resource independently would accept a combination that has nothing to
     * fetch.
     *
     * @var array<string, list<string>>
     */
    private const CATALOGUE_RESOURCES = [
        'heygen' => ['voice', 'avatar'],
        'tavus' => ['voice', 'replica', 'pal'],
        // Voice-only TTS vendors: not avatar providers, so no template can
        // have them as `provider` (PROVIDERS above stays heygen|tavus).
        'cartesia' => ['voice'],
        'elevenlabs' => ['voice'],
    ];

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AvatarTemplate::class);

        return AvatarTemplateResource::collection(
            AvatarTemplate::orderByDesc('is_active')->orderBy('name')->get()
        );
    }

    /**
     * The picker list: id, name, provider. Nothing else.
     *
     * `index()` above stays admin-only because this resource carries `config`,
     * and that holds provider-side identifiers (avatarId, voiceId, faceId,
     * palId) which are closer to credentials than to settings. But
     * `projects.avatar_template_id` is NOT NULL, so every operator creating a
     * project has to choose one — and an operator who cannot list templates
     * cannot choose. Their select came back empty and the form refused its own
     * submit, with nothing they could do about it.
     *
     * So this is the narrow answer rather than a widened `viewAny`: exactly
     * the five fields choosing a template requires — `id`, `name`, `provider`,
     * `is_active` and `scope` (`organization` | `platform`, so a picker can
     * group and badge the platform templates it is offered), which is what the
     * method below returns and what `openapi.json` publishes. A viewer gets it too —
     * reading a project's configuration should show which template it names,
     * not a bare id.
     *
     * The shape is hand-built rather than run through `AvatarTemplateResource`
     * ON PURPOSE. A resource is a list of fields somebody will add to; this
     * endpoint's entire value is that it cannot grow one, and a future field on
     * that resource must not silently become readable by every role.
     */
    public function options(): JsonResponse
    {
        $this->authorize('listOptions', AvatarTemplate::class);

        // Own templates plus the platform ones, through the named scope. A
        // platform template is offered only while it is active, or while a
        // live project of THIS organization still pins it: the edit form has
        // to render its current pin, and the project subquery is tenant-scoped
        // so another organization's pin never surfaces a retired global here.
        // A bare superadmin has no organization to offer anything to and sees
        // every row. Organization rows first, then active, then by name.
        $options = AvatarTemplate::availableToTenant()
            ->when(
                ! app(TenantResolver::class)->isBypass(),
                fn (Builder $query) => $query->where(fn (Builder $offered) => $offered
                    ->whereNotNull('organization_id')
                    ->orWhere('is_active', true)
                    ->orWhereIn('id', Project::query()->select('avatar_template_id')))
            )
            ->orderByRaw('(avatar_templates.organization_id IS NULL) ASC')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (AvatarTemplate $template): array => [
                'id' => (int) $template->id,
                'name' => $template->name,
                'provider' => $template->provider,
                'is_active' => (bool) $template->is_active,
                'scope' => $template->scopeLabel(),
            ])
            ->all();

        return response()->json(['data' => $options]);
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

    // Internal notes, not published (Scramble exports docblock prose as public text):
    // A provider's real inventory for one resource type — the picker's data
    // source (avatar-template-catalogue PR1, design D1/D3/D4).
    //
    // Gated by the SAME `viewAny` ability as `fieldSpecs()` above: this
    // endpoint proxies a platform-level provider account (no tenant data of
    // its own), but it carries provider-side identifiers the picker will let
    // an admin select — the same "closer to credentials than to settings"
    // reasoning `AvatarTemplatePolicy` already applies to `config`.
    //
    // Never a 500: `AvatarProviderCatalogue::fetch()` degrades a provider
    // failure to `{status: 'unavailable', items: []}` on its own (D3); this
    // action's only failure mode is a 422 for an unrecognized
    // `provider`/`resource` pair, checked BEFORE ever calling the provider.
    /**
     * List a provider's catalogue.
     *
     * Returns what the provider offers for one resource type (voices, avatars, replicas or
     * personas), as the data source of the template picker. A provider failure is reported as
     * `{status: "unavailable", items: []}`, not as an error; an unrecognized `provider`/`resource`
     * pair answers `422`.
     */
    public function catalogue(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AvatarTemplate::class);

        // `provider` carries a literal 'in:' list, not 'in:'.implode(',', self::PROVIDERS):
        // Scramble's static analyzer cannot evaluate implode() over a class constant and
        // was emitting an empty-string-only enum for `provider` in openapi.json, making
        // the documented endpoint unreachable and poisoning the generated TS client with
        // `provider: ""` (avatar-template-catalogue, caught by native review).
        $validated = $request->validate([
            // The provider to query: `heygen`, `tavus`, `cartesia` or `elevenlabs`.
            'provider' => ['required', 'string', 'in:heygen,tavus,cartesia,elevenlabs'],
            'resource' => ['required', 'string', 'in:voice,avatar,replica,pal'],
            // Keep only genuinely Italian voices (`italian` = native). The
            // list is already sorted Italian-first without it.
            'italian_only' => ['sometimes', 'boolean'],
        ]);

        $provider = $validated['provider'];
        $resource = $validated['resource'];

        if (! in_array($resource, self::CATALOGUE_RESOURCES[$provider], true)) {
            throw ValidationException::withMessages([
                'resource' => 'unknown_resource_for_provider',
            ]);
        }

        $catalogue = AvatarProviderCatalogue::fetch($provider, $resource);

        if ($request->boolean('italian_only')) {
            $catalogue['items'] = array_values(array_filter(
                $catalogue['items'],
                static fn (array $item): bool => ($item['italian'] ?? null) === 'native',
            ));
        }

        return response()->json(['data' => $catalogue]);
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

        $validated = $request->validate($this->templateStoreRules());

        $this->assertConfigValid($validated['provider'], $validated['config']);
        $this->assertNameFreeAmong(AvatarTemplate::query(), $validated['name'], null);

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

        $validated = $request->validate($this->templateUpdateRules());

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
            $this->assertNameFreeAmong(AvatarTemplate::query(), $validated['name'], $template->id);
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
            //
            // Filtered by the template's OWN organization explicitly, not by the
            // implicit tenant scope: that scope filters nothing under superadmin
            // bypass, so a bare superadmin used to deactivate the active
            // template of EVERY organization on this provider.
            AvatarTemplate::where('organization_id', $template->organization_id)
                ->where('is_active', true)
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

    /**
     * Take a template out of service without deleting it.
     *
     * Only `activate` existed, so the only ways to stop offering a template
     * were to activate a different one — which needs a different one to exist —
     * or to delete it, which is destructive and is now refused outright while
     * any project pins it.
     *
     * What deactivation MEANS changed with the mandatory pin, and for the
     * better. `is_active` used to be the organization-wide fallback, so
     * switching it off silently changed which template every unpinned project
     * ran on. Every project now names its own, so `is_active` is only "the one
     * offered as the default for new projects" — turning it off is reversible
     * bookkeeping, not a live reconfiguration.
     *
     * NO config revalidation, unlike `activate()`. That check exists to catch a
     * stale config before a candidate meets it; withdrawing a template can only
     * reduce exposure, and refusing to withdraw an ALREADY-invalid one would
     * trap an operator with exactly the template they most want to retire.
     *
     * Idempotent: deactivating an inactive template is a no-op, so a double
     * click or two operators acting at once never produce a failure for a state
     * that is already correct.
     */
    public function deactivate(int $id): AvatarTemplateResource
    {
        $template = AvatarTemplate::findOrFail($id);
        $this->authorize('activate', $template);

        $wasActive = $template->is_active;

        if ($wasActive) {
            $template->update(['is_active' => false]);

            // Only when something actually changed — an audit trail of no-ops
            // is noise that makes the real entries harder to find.
            app(AuditRecorder::class)->record(
                'avatar_template.deactivated',
                'avatar_template',
                $template->id,
                before: ['name' => $template->name, 'provider' => $template->provider],
            );
        }

        return new AvatarTemplateResource($template->fresh());
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
}
