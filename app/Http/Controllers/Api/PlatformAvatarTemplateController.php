<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AvatarTemplates\BindHeygenTemplateVoice;
use App\Actions\AvatarTemplates\DuplicateAvatarTemplate;
use App\Exceptions\AvatarTemplateInUseException;
use App\Http\Controllers\Concerns\ValidatesAvatarTemplateWrites;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformAvatarTemplateResource;
use App\Models\AvatarTemplate;
use App\Models\User;
use App\Services\ConversationLlm\HeygenLlmRegistrar;
use App\Support\AvatarTemplates\ConfigValidator;
use App\Support\AvatarTemplates\GlobalAvatarTemplateUsage;
use App\Support\AvatarTemplates\PlatformTemplateContext;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use App\Support\Superadmin\PlatformAuditWriter;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform (global) avatar templates: rows with no organization, offered to
 * every organization for new project pins (global-avatar-templates D4).
 *
 * Routes (auth:api + TenantContext, superadmin-only, NO `org.context`):
 *   GET   /admin/avatar-templates
 *   POST  /admin/avatar-templates
 *   GET   /admin/avatar-templates/{id}
 *   PATCH /admin/avatar-templates/{id}
 *   POST  /admin/avatar-templates/{id}/activate     (offer for new project pins)
 *   POST  /admin/avatar-templates/{id}/deactivate   (retire; existing pins are untouched)
 *   DELETE /admin/avatar-templates/{id}             (only when retired AND unpinned)
 *   POST  /admin/avatar-templates/{id}/duplicate    (copy into organizations)
 *
 * Every write runs inside `PlatformTemplateContext::run()` — the one door
 * through which a NULL-organization row can be persisted — and inside ONE
 * transaction with its `PlatformAuditWriter` row: an unaudited platform
 * mutation cannot exist, and a failed audit write rolls the mutation back and
 * fails the request. Provider sync (an HTTP call) runs AFTER the commit.
 *
 * Every lookup is `AvatarTemplate::platformOnly()`, so an organization's
 * template id is a plain 404 here, exactly as a platform id is a 404 on every
 * organization route. The caller is a superadmin either way — bare, or acting
 * as an organization — and the acting organization is never consulted.
 *
 * The `abort_unless` is written out at every action rather than hidden in a
 * helper, for the reason `PlatformUserController` records: Scramble documents
 * only the refusals a controller visibly makes.
 */
final class PlatformAvatarTemplateController extends Controller
{
    use ValidatesAvatarTemplateWrites;

    public function __construct(
        private readonly GlobalAvatarTemplateUsage $usage,
        private readonly PlatformTemplateContext $context,
        private readonly PlatformAuditWriter $audit,
    ) {}

    private function isSuperadmin(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->is_superadmin === true;
    }

    /**
     * The field specs a platform template accepts, including the platform-only
     * ones (the external HeyGen voice) that the organization route leaves out.
     *
     * Machine-facing and NOT localized, like the organization route's.
     *
     * @throws AuthorizationException
     */
    public function fieldSpecs(Request $request): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $specs = [];

        foreach (['heygen', 'tavus'] as $provider) {
            $specs[$provider] = array_map(
                fn ($field): array => $field->toArray(),
                ProviderFieldSpecs::for($provider),
            );
        }

        return response()->json(['data' => $specs]);
    }

    /**
     * List platform avatar templates with their usage.
     *
     * @response array{data: list<\App\Http\Resources\PlatformAvatarTemplateResource>}
     *
     * @throws AuthorizationException
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $templates = AvatarTemplate::platformOnly()->orderByDesc('is_active')->orderBy('name')->get();
        $usage = $this->usage->for(array_values(array_map(intval(...), $templates->modelKeys())));

        return new AnonymousResourceCollection(
            $templates->map(fn (AvatarTemplate $template): PlatformAvatarTemplateResource => new PlatformAvatarTemplateResource($template, $usage[$template->id])),
            PlatformAvatarTemplateResource::class,
        );
    }

    /**
     * Show a platform avatar template with its usage.
     *
     * @throws AuthorizationException
     */
    public function show(Request $request, int $id): PlatformAvatarTemplateResource
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        return $this->present(AvatarTemplate::platformOnly()->findOrFail($id));
    }

    /**
     * Create a platform avatar template.
     *
     * Created INACTIVE and never activatable through this payload: creating a
     * template must not change what candidates are being interviewed with
     * right now, and offering it to every organization is its own decision.
     *
     * @throws AuthorizationException
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate($this->templateStoreRules());

        $this->assertConfigValid($validated['provider'], $validated['config'], platform: true);
        app(BindHeygenTemplateVoice::class)->run($validated['provider'], $validated['config']);
        $this->assertNameFreeAmong(AvatarTemplate::platformOnly(), $validated['name'], null);

        $template = $this->answeringPlatformNameRace(fn (): AvatarTemplate => $this->context->run($actor, fn (): AvatarTemplate => DB::transaction(function () use ($validated, $actor): AvatarTemplate {
            $template = AvatarTemplate::create($validated);

            $this->audit->record(
                $actor->id,
                'avatar_template.created',
                'avatar_template',
                $template->id,
                null,
                ['name' => $template->name, 'provider' => $template->provider, 'scope' => 'platform'],
            );

            return $template;
        })));

        return $this->present($template)
            ->additional($this->recordSync($template))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Edit a platform avatar template.
     *
     * An edit reaches every project that pins this template, in every organization, on the next read.
     * The audit trail records the usage at edit time and the names of the changed fields, never the
     * config values.
     *
     * @throws AuthorizationException
     */
    public function update(Request $request, int $id): PlatformAvatarTemplateResource
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        $template = AvatarTemplate::platformOnly()->findOrFail($id);
        $validated = $request->validate($this->templateUpdateRules());

        // The provider is immutable: the config's knobs belong to it, so a
        // template that changed provider would validate as empty and quietly
        // fall back to the environment defaults.
        if ($request->has('provider') && $request->input('provider') !== $template->provider) {
            throw ValidationException::withMessages([
                'provider' => 'The provider cannot be changed after creation. Create a new template instead.',
            ]);
        }

        if (array_key_exists('config', $validated)) {
            $this->assertConfigValid($template->provider, $validated['config'], platform: true);
            app(BindHeygenTemplateVoice::class)->run($template->provider, $validated['config']);
        }

        if (array_key_exists('name', $validated)) {
            $this->assertNameFreeAmong(AvatarTemplate::platformOnly(), $validated['name'], $template->id);
        }

        $before = ['name' => $template->name];
        $originals = $template->only(array_keys($validated));
        $beforeBinding = $this->bindingNames($template);
        // An edit reaches EVERY project that pins this template, in every organization,
        // on the next read (live edit, design D1) — which is why the audit row carries
        // the usage at edit time: the reach of the change is part of what happened. It
        // records field NAMES, never config values.
        $usage = $this->usage->for([$template->id])[$template->id];

        $this->answeringPlatformNameRace(fn () => $this->context->run($actor, fn () => DB::transaction(function () use ($template, $validated, $actor, $before, $originals, $beforeBinding, $usage): void {
            $template->update($validated);

            $changed = array_values(array_filter(
                array_keys($validated),
                fn (string $field): bool => $this->canonical($originals[$field]) !== $this->canonical($template->{$field}),
            ));

            // An audit trail of no-ops is noise that hides the real entries.
            if ($changed === []) {
                return;
            }

            $this->audit->record($actor->id, 'avatar_template.updated', 'avatar_template', $template->id, $before, [
                'name' => $template->name,
                'scope' => 'platform',
                'changed_fields' => $changed,
                'usage' => $usage,
            ]);

            if (array_intersect($changed, ['llm_model_id', 'llm_credential_id']) !== []) {
                $unbound = $template->llm_model_id === null && $template->llm_credential_id === null;

                $this->audit->record(
                    $actor->id,
                    $unbound ? 'avatar_template.llm_unbound' : 'avatar_template.llm_bound',
                    'avatar_template',
                    $template->id,
                    $beforeBinding,
                    $unbound ? null : $this->bindingNames($template->refresh()),
                );
            }
        })));

        return $this->present($template)->additional($this->recordSync($template));
    }

    /**
     * Offer a platform avatar template for new project pins.
     *
     * "Offered" is not "the one in use": any number of platform templates may
     * be offered at once (a single active row per provider is an organization
     * rule), so nothing else is deactivated. The stored config is validated
     * again HERE because a config goes stale when the field spec changes, and
     * offering is the last moment anyone can catch that before an organization
     * pins it. Idempotent: offering an offered template changes and audits
     * nothing.
     *
     * @throws AuthorizationException
     */
    public function activate(Request $request, int $id): PlatformAvatarTemplateResource
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        $template = AvatarTemplate::platformOnly()->findOrFail($id);

        if (! $template->is_active) {
            $this->assertConfigValid($template->provider, $template->config, platform: true);
            $this->setOffered($actor, $template, true);

            return $this->present($template)->additional($this->recordSync($template));
        }

        return $this->present($template);
    }

    /**
     * Retire a platform avatar template: it is no longer offered for NEW pins.
     *
     * Existing pins keep resolving to it (a pin is valid in any state), so
     * retiring is reversible bookkeeping and always allowed — including while
     * projects in other organizations still use it. No config revalidation:
     * withdrawing can only reduce exposure, and an already-invalid template is
     * exactly the one an operator most wants to retire. Idempotent.
     *
     * @throws AuthorizationException
     */
    public function deactivate(Request $request, int $id): PlatformAvatarTemplateResource
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        $template = AvatarTemplate::platformOnly()->findOrFail($id);

        if ($template->is_active) {
            $this->setOffered($actor, $template, false);
        }

        return $this->present($template);
    }

    /**
     * Delete a platform avatar template: only when it is retired AND unpinned.
     *
     * Two 409s, in this order. `template_active`: deleting what organizations
     * are being offered is a decision, not a cleanup — retire it first.
     * `template_in_use`: a pin in ANY organization refuses the delete, and the
     * body carries the organization and project counts so the superadmin knows
     * how far the blast radius reaches. Trashed projects do not count. The
     * count-then-delete window is closed by the model's own `deleting` guard,
     * whose exception renders as the same 409.
     *
     * @throws AuthorizationException
     */
    #[ResponseDoc(204, description: 'The template was deleted.', type: 'null')]
    #[ResponseDoc(409, description: 'Still offered (`template_active`) or pinned by projects (`template_in_use`, with counts).', type: "array{error: 'template_active'|'template_in_use', message: string, project_count?: int, organization_count?: int}")]
    public function destroy(Request $request, int $id): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        $template = AvatarTemplate::platformOnly()->findOrFail($id);

        if ($template->is_active) {
            return response()->json(['error' => 'template_active', 'message' => 'template_active'], Response::HTTP_CONFLICT);
        }

        $usage = $this->usage->for([$template->id])[$template->id];

        if ($usage['project_count'] > 0) {
            throw new AvatarTemplateInUseException($usage['project_count'], $usage['organization_count']);
        }

        if ($template->provider === 'heygen') {
            // Never throws (design D8): deleting OUR row must not be blocked by
            // an unreachable HeyGen account.
            app(HeygenLlmRegistrar::class)->forget($template);
        }

        $this->context->run($actor, fn () => DB::transaction(function () use ($actor, $template): void {
            $this->audit->record(
                $actor->id,
                'avatar_template.deleted',
                'avatar_template',
                $template->id,
                ['name' => $template->name, 'provider' => $template->provider, 'scope' => 'platform'],
                null,
            );

            $template->delete();
        }));

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Copy a platform avatar template into one or more organizations.
     *
     * This is the ONLY route that copies a platform template: the organization
     * duplicate route answers 404 for a platform id, like every organization
     * route. Each copy is an independent, INACTIVE organization template (no
     * shared provider-side configuration), so editing the platform template
     * afterwards never reaches it. Written OUTSIDE the platform context — the
     * copies belong to their target organizations — and audited per target by
     * the tenant recorder, with `source_scope: platform`.
     *
     * @response array{data: list<array{organization_id: int, id: int, name: string}>}
     *
     * @throws AuthorizationException
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $validated = $request->validate([
            'target_organization_ids' => ['required', 'array', 'min:1'],
            'target_organization_ids.*' => ['required', 'integer', 'distinct', Rule::exists('organizations', 'id')],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $template = AvatarTemplate::platformOnly()->findOrFail($id);

        // A copy of a template that no longer validates would just move the
        // problem into another organization.
        if (ConfigValidator::validate($template->provider, $template->config) !== []) {
            throw ValidationException::withMessages(['template' => 'source_config_invalid']);
        }

        /** @var list<int> $targets */
        $targets = array_map(intval(...), $validated['target_organization_ids']);

        $created = app(DuplicateAvatarTemplate::class)->run($template, $targets, $validated['name'] ?? null);

        return response()->json(['data' => $created], Response::HTTP_CREATED);
    }

    /**
     * The flag write and its audit row share one transaction. The row carries
     * the usage at that moment: how many organizations and projects a change of
     * availability reaches is part of what happened.
     */
    private function setOffered(User $actor, AvatarTemplate $template, bool $offered): void
    {
        $usage = $this->usage->for([$template->id])[$template->id];
        $snapshot = ['name' => $template->name, 'provider' => $template->provider, 'scope' => 'platform', 'usage' => $usage];

        $this->context->run($actor, fn () => DB::transaction(function () use ($actor, $template, $offered, $snapshot): void {
            $template->update(['is_active' => $offered]);

            $this->audit->record(
                $actor->id,
                $offered ? 'avatar_template.activated' : 'avatar_template.deactivated',
                'avatar_template',
                $template->id,
                $offered ? null : $snapshot,
                $offered ? $snapshot : null,
            );
        }));
    }

    /**
     * A value with its object keys ordered, so "changed" means changed and not
     * re-serialized: Postgres `jsonb` hands the keys back in its own order, and
     * Eloquent's array cast compares them positionally.
     */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($this->canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Names, never ids — the AuditRecorder doctrine for a binding change.
     *
     * @return array{model_key: string|null, credential_name: string|null}
     */
    private function bindingNames(AvatarTemplate $template): array
    {
        return [
            'model_key' => $template->llmModel?->key,
            'credential_name' => $template->llmCredential?->name,
        ];
    }

    private function present(AvatarTemplate $template): PlatformAvatarTemplateResource
    {
        return new PlatformAvatarTemplateResource($template, $this->usage->for([$template->id])[$template->id]);
    }
}
