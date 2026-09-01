<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LlmCredentialResource;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Services\ConversationLlm\GeminiKeyValidator;
use App\Services\ConversationLlm\HeygenLlmRegistrar;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Org-scoped CRUD over `llm_credentials` (pluggable-conversation-llm PR P2,
 * design D2/D9).
 *
 * Admin only, enforced by LlmCredentialPolicy. Cross-org access is not
 * checked here — LlmCredential is a TenantModel, so another tenant's row is
 * never found at all, a 404 rather than a 403 (same doctrine as
 * AvatarTemplateController).
 *
 * `store`/`update` are the ONLY paths that reach GeminiKeyValidator — there
 * is deliberately no "test without saving" endpoint (design D9), so
 * validating a key requires being `admin` on an org you already belong to,
 * and both routes carry `throttle:5,1`.
 */
final class LlmCredentialController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', LlmCredential::class);

        return LlmCredentialResource::collection(LlmCredential::orderBy('name')->get());
    }

    public function show(int $id): LlmCredentialResource
    {
        $credential = LlmCredential::findOrFail($id);
        $this->authorize('view', $credential);

        return new LlmCredentialResource($credential);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', LlmCredential::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'vendor' => ['required', 'string', 'in:google'],
            'api_key' => ['required', 'string'],
        ]);

        $this->assertNameFree($validated['name'], null);

        $code = app(GeminiKeyValidator::class)->validate($validated['api_key']);

        // Never Google's prose, and never persisted as active — an admin
        // must not learn that a key which is dead behaves like one that
        // saved (design D9's asymmetric store rule).
        if ($code === 'invalid_key') {
            throw ValidationException::withMessages(['api_key' => $code]);
        }

        $credential = LlmCredential::create([
            'name' => $validated['name'],
            'vendor' => $validated['vendor'],
            'api_key' => $validated['api_key'],
            'key_last_four' => substr($validated['api_key'], -4),
            'key_fingerprint' => hash('sha256', $validated['api_key']),
            'validated_at' => $code === 'valid' ? now() : null,
            'validation_error' => $code === 'valid' ? null : $code,
        ]);

        app(AuditRecorder::class)->record(
            'llm_credential.created',
            'llm_credential',
            $credential->id,
            after: [
                'name' => $credential->name,
                'key_last_four' => $credential->key_last_four,
                'key_fingerprint' => $credential->key_fingerprint,
            ],
        );

        return (new LlmCredentialResource($credential))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, int $id): LlmCredentialResource
    {
        $credential = LlmCredential::findOrFail($id);
        $this->authorize('update', $credential);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'api_key' => ['sometimes', 'string'],
        ]);

        if (array_key_exists('name', $validated)) {
            $this->assertNameFree($validated['name'], $credential->id);
        }

        // Rotation. Validated BEFORE anything is written, so an invalid key
        // leaves the row byte-unchanged (D9's asymmetric store rule applies
        // identically on update).
        if (array_key_exists('api_key', $validated)) {
            $code = app(GeminiKeyValidator::class)->validate($validated['api_key']);

            if ($code === 'invalid_key') {
                throw ValidationException::withMessages(['api_key' => $code]);
            }

            $credential->fill([
                'api_key' => $validated['api_key'],
                'key_last_four' => substr($validated['api_key'], -4),
                'key_fingerprint' => hash('sha256', $validated['api_key']),
                'validated_at' => $code === 'valid' ? now() : null,
                'validation_error' => $code === 'valid' ? null : $code,
            ]);
        }

        if (array_key_exists('name', $validated)) {
            $credential->name = $validated['name'];
        }

        $credential->save();

        // Rotate the vendor secret ONLY when one already exists — a
        // credential never bound to a HeyGen template has no
        // `heygen_secret_id` yet, and eagerly registering one here would
        // create a secret HeyGen never uses (design D8: `secret_name` is not
        // unique, so an eager POST on every save risks an orphan). The
        // first HeyGen template that binds this credential creates its
        // secret fresh, via `HeygenLlmRegistrar::ensureConfiguration()`.
        if (array_key_exists('api_key', $validated) && $credential->heygen_secret_id !== null) {
            app(HeygenLlmRegistrar::class)->rotateSecret($credential);
        }

        if (array_key_exists('api_key', $validated)) {
            app(AuditRecorder::class)->record(
                'llm_credential.rotated',
                'llm_credential',
                $credential->id,
                after: [
                    'name' => $credential->name,
                    'key_last_four' => $credential->key_last_four,
                    'key_fingerprint' => $credential->key_fingerprint,
                ],
            );
        }

        return new LlmCredentialResource($credential);
    }

    public function destroy(int $id): JsonResponse
    {
        $credential = LlmCredential::findOrFail($id);
        $this->authorize('delete', $credential);

        // The (organization_id, llm_credential_id) index (design D3) makes
        // this one query. Mirrors AvatarTemplateController::destroy()'s 409
        // `template_active` — the request is well-formed, the state is what
        // refuses it.
        $boundTemplateNames = AvatarTemplate::where('llm_credential_id', $credential->id)
            ->pluck('name')
            ->all();

        if ($boundTemplateNames !== []) {
            return response()->json([
                'error' => 'credential_in_use',
                // A code, not a sentence: the API has no idea what language
                // the operator reads, and `templates` below already names the
                // ones blocking the delete.
                'message' => 'credential_in_use',
                'templates' => $boundTemplateNames,
            ], Response::HTTP_CONFLICT);
        }

        // Reached only once nothing references this credential — never
        // throws (design D8), so an unreachable HeyGen account cannot block
        // deleting OUR row.
        app(HeygenLlmRegistrar::class)->forgetSecret($credential);

        app(AuditRecorder::class)->record(
            'llm_credential.deleted',
            'llm_credential',
            $credential->id,
            before: [
                'name' => $credential->name,
                'key_last_four' => $credential->key_last_four,
                'key_fingerprint' => $credential->key_fingerprint,
            ],
        );

        $credential->delete();

        return response()->json(null, Response::HTTP_OK);
    }

    private function assertNameFree(string $name, ?int $exceptId): void
    {
        $query = LlmCredential::where('name', $name);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if (! $query->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'name' => 'A credential with this name already exists.',
        ]);
    }
}
