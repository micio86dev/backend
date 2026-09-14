<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LlmCredentialResource;
use App\Jobs\ResyncCredentialBindingsJob;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Services\ConversationLlm\GeminiKeyValidator;
use App\Services\ConversationLlm\HeygenLlmRegistrar;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * PLATFORM-WIDE CRUD over `llm_credentials` (RATIFIED 2026-09-14).
 *
 * SUPERADMIN only, enforced by `LlmCredentialPolicy`. These rows stopped
 * being an organization's bring-your-own key and became BEAI's own — one set,
 * serving every tenant — so there is no tenant scope here any more and no
 * cross-org question to answer: a caller either may see all of them or none.
 *
 * `index()` therefore returns the whole table unfiltered. That is not the
 * accidental all-tenants read a missing scope usually signals; it is the
 * entire collection, and only a superadmin ever reaches it.
 *
 * `store`/`update` are the ONLY paths that reach GeminiKeyValidator — there
 * is deliberately no "test without saving" endpoint (design D9), so
 * validating a key requires being a superadmin, and both routes carry
 * `throttle:5,1`.
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

        $warning = null;

        if (array_key_exists('api_key', $validated)) {
            // TWO STEPS, in this order, and BOTH on every key change.
            //
            // 1. The HeyGen SECRET, only when one already exists. A credential
            //    never bound to a HeyGen template has no `heygen_secret_id`,
            //    and eagerly registering one here would create a secret HeyGen
            //    never uses (design D8: `secret_name` is not unique, so an
            //    eager POST on every save risks an orphan). The first HeyGen
            //    template to bind this credential creates its secret fresh.
            //    First, because a configuration can only be re-pushed against
            //    a secret that already exists.
            if ($credential->heygen_secret_id !== null) {
                $rotation = app(HeygenLlmRegistrar::class)->rotateSecret($credential);

                // REPORTED, not discarded — `AvatarTemplateController::
                // recordSync()` states the doctrine: an operator who is not
                // told will believe the setting took effect.
                //
                // This failure is worse than silent, it is destructive.
                // `forgetSecret()` nulls `heygen_secret_id` whether or not the
                // vendor call succeeded (its NEVER-THROWS contract), so if
                // `ensureSecret()` then fails — HeyGen down, platform key
                // unset — the old secret is gone, no new one exists, and every
                // bound configuration references something deleted. Answering
                // a bare 200 there tells the operator a rotation worked.
                //
                // Synchronous, so unlike the queued sweep below there is
                // nothing forcing this one to be quiet.
                if ($rotation['status'] === 'warning') {
                    $warning = $rotation['message'] ?? 'llm_secret_failed';
                }
            }

            // 2. Every bound template, EVERY provider — QUEUED.
            //
            //    This half used to be missing entirely for Tavus: the sweep
            //    lived inside `rotateSecret()` behind a `provider = 'heygen'`
            //    filter, and the call above is gated on
            //    `heygen_secret_id !== null`, so a credential bound only to
            //    Tavus templates was never re-pushed at all. `TavusPalSync`
            //    puts `api_key` literally on the wire and Tavus does not
            //    retain it across PATCHes, so the PAL went on authenticating
            //    with the OLD key while our row still read `synced` — which
            //    `LlmBindingResolver::resolveStatus()` reports as `Applied`,
            //    the one state its docblock calls billable.
            //
            //    OFF THE REQUEST, because credentials are platform rows now:
            //    the sweep spans every tenant's templates, and each HeyGen one
            //    can cost two 10-second vendor calls. Run inline, a large
            //    enough estate plus one slow vendor times the PATCH out — and
            //    the templates the sweep never reached keep reading `synced`,
            //    which is the same lie by a different route.
            //
            //    Dispatched AFTER the secret rotation above rather than inside
            //    it: a HeyGen configuration can only be re-pushed against a
            //    secret that already exists, and that rotation is synchronous.
            ResyncCredentialBindingsJob::dispatch((int) $credential->id);
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

        $resource = new LlmCredentialResource($credential);

        return $warning === null ? $resource : $resource->additional(['warning' => $warning]);
    }

    public function destroy(int $id): JsonResponse
    {
        $credential = LlmCredential::findOrFail($id);
        $this->authorize('delete', $credential);

        // Read BEFORE anything is destroyed. After `delete()` the row is gone,
        // and an audit trail carrying only an id says nothing about what was
        // removed; `$secretId` is needed after the row is gone too.
        $before = [
            'name' => $credential->name,
            'key_last_four' => $credential->key_last_four,
            'key_fingerprint' => $credential->key_fingerprint,
        ];
        $secretId = $credential->heygen_secret_id;
        $credentialId = (int) $credential->id;

        // GUARD AND DELETE IN ONE LOCKED TRANSACTION — the race is PREVENTED,
        // not caught.
        //
        // Unlocked, these are two statements with a gap: a template can bind
        // this credential between the count and the delete, and because
        // `avatar_templates.llm_credential_id` is ON DELETE RESTRICT, Postgres
        // answers that gap with an integrity error — a 500 for a request whose
        // honest answer is the 409 below.
        //
        // `lockForUpdate()` closes it at the database rather than in PHP.
        // Inserting a row that REFERENCES this credential makes Postgres take
        // a `FOR KEY SHARE` lock on it to check the foreign key, and that
        // conflicts with the `FOR UPDATE` held here — so a concurrent bind
        // waits for this transaction to finish instead of slipping between the
        // two statements. Catching the violation afterwards would also work,
        // but it answers a race we lost with a list we can no longer trust;
        // this way the count is authoritative by the time it is read.
        //
        // `withoutGlobalScopes()` is REQUIRED, and its absence would be a 500
        // rather than a leak. `AvatarTemplate` is a TenantModel; the credential
        // no longer is. A superadmin ACTING AS a client has the tenant scope
        // switched back ON (`TenantContext` sets `bypass=false` when a client
        // is selected), so a scoped count would see only that client's
        // templates while the foreign key spans EVERY tenant: the guard would
        // report "nothing bound" and Postgres would refuse the delete anyway.
        //
        // The same disagreement the soft-delete comment in `AvatarTemplate`
        // documents — a guard applying a scope the foreign key does not —
        // arriving here by a different route.
        $boundTemplateNames = DB::transaction(function () use ($credential, $credentialId): array {
            LlmCredential::whereKey($credentialId)->lockForUpdate()->first();

            $bound = AvatarTemplate::withoutGlobalScopes()
                ->where('llm_credential_id', $credentialId)
                ->pluck('name')
                ->all();

            if ($bound === []) {
                $credential->delete();
            }

            return $bound;
        });

        if ($boundTemplateNames !== []) {
            return response()->json([
                'error' => 'credential_in_use',
                // A code, not a sentence: the API has no idea what language
                // the operator reads, and `templates` already names the ones
                // blocking the delete.
                'message' => 'credential_in_use',
                'templates' => $boundTemplateNames,
            ], Response::HTTP_CONFLICT);
        }

        // The vendor secret is destroyed only once OUR row is durably gone.
        // Doing it first — as this did — meant a delete that threw left the
        // row alive with its HeyGen secret already destroyed and every bound
        // template broken, which is the one outcome here that cannot be
        // undone.
        //
        // `forgetVendorSecret()`, NOT `forgetSecret()`: the latter nulls
        // `heygen_secret_id` with `saveQuietly()`, and `Model::delete()` has
        // already set `exists = false`, so that save would take Eloquent's
        // INSERT branch and resurrect the credential under a fresh id. Never
        // throws (design D8), so an unreachable HeyGen account cannot fail a
        // request whose durable work is already committed.
        app(HeygenLlmRegistrar::class)->forgetVendorSecret($secretId, $credentialId);

        // Recorded AFTER the durable DB write, so the trail never claims a
        // deletion that did not commit — the invariant
        // `ApiClientController::destroy()` states for revocation.
        app(AuditRecorder::class)->record(
            'llm_credential.deleted',
            'llm_credential',
            $credentialId,
            before: $before,
        );

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
