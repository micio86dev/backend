<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\AvatarTemplateResource;
use App\Models\AvatarTemplate;
use App\Support\Audit\AuditRecorder;
use App\Support\AvatarTemplates\ConfigValidator;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
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

        $before = ['name' => $template->name];
        $template->update($validated);

        app(AuditRecorder::class)->record(
            'avatar_template.updated',
            'avatar_template',
            $template->id,
            before: $before,
            after: ['name' => $template->name],
        );

        return new AvatarTemplateResource($template);
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
            AvatarTemplate::where('is_active', true)
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
                'message' => 'Activate another template before deleting this one.',
            ], Response::HTTP_CONFLICT);
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

        // Every problem at once. One error per round trip turns filling in a
        // seventeen-field form into a guessing game.
        throw ValidationException::withMessages([
            'config' => array_map(
                fn (array $e): string => "{$e['key']}: {$e['code']}",
                $errors,
            ),
        ]);
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
}
