<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ValidatesAvatarTemplateWrites;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformAvatarTemplateResource;
use App\Models\AvatarTemplate;
use App\Models\User;
use App\Support\AvatarTemplates\GlobalAvatarTemplateUsage;
use App\Support\AvatarTemplates\PlatformTemplateContext;
use App\Support\Superadmin\PlatformAuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
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
     * List platform avatar templates with their usage.
     *
     * @response array{data: list<\App\Http\Resources\PlatformAvatarTemplateResource>}
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
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate($this->templateStoreRules());

        $this->assertConfigValid($validated['provider'], $validated['config']);
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
     * An edit reaches EVERY project that pins this template, in every
     * organization, on the next read (live edit, design D1) — which is why the
     * audit row carries the usage at edit time: the reach of the change is part
     * of what happened. It records field NAMES, never config values.
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
            $this->assertConfigValid($template->provider, $validated['config']);
        }

        if (array_key_exists('name', $validated)) {
            $this->assertNameFreeAmong(AvatarTemplate::platformOnly(), $validated['name'], $template->id);
        }

        $before = ['name' => $template->name];
        $originals = $template->only(array_keys($validated));
        $beforeBinding = $this->bindingNames($template);
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
