<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Support\AvatarTemplates\ConfigValidator;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use App\Support\AvatarTemplates\TemplateDocument;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON export / import of avatar template configuration (C14 portability).
 *
 * Configuration is tuned in the sibling `quint-avatar-tester` project and had
 * no way into BEAI except retyping it into a form.
 *
 * ADMIN ONLY, both directions (design D10). Export is a read of configuration
 * an operator can already see field by field, but as one file it is also the
 * fastest way to lift configuration out of a tenant; import can change what
 * every future interview runs on.
 *
 * REQ: Avatar template configuration is exportable and importable as JSON
 */
final class AvatarTemplatePortabilityController extends Controller
{
    public function __construct(
        private readonly TenantResolver $resolver,
    ) {}

    /**
     * GET /api/avatar-templates/export
     */
    public function export(): JsonResponse
    {
        $this->authorize('create', AvatarTemplate::class);

        $templates = AvatarTemplate::query()
            ->where('organization_id', $this->resolver->getOrgId())
            ->orderBy('id')
            ->get();

        return response()->json(TemplateDocument::export($templates));
    }

    /**
     * POST /api/avatar-templates/import
     *
     * All-or-nothing. A partial import leaves the operator believing a
     * configuration is present when it is not, which is worse than a refusal:
     * they find out at interview time, on a candidate.
     */
    public function import(Request $request): JsonResponse
    {
        $this->authorize('create', AvatarTemplate::class);

        $validated = $request->validate([
            'schema' => ['required', 'string'],
            'templates' => ['required', 'array'],
        ]);

        if ($validated['schema'] !== TemplateDocument::SCHEMA) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['schema' => [
                    'Unsupported document version. Expected '.TemplateDocument::SCHEMA.'.',
                ]],
            ], 422);
        }

        $records = [];
        $errors = [];

        foreach ($validated['templates'] as $index => $entry) {
            if (! is_array($entry)) {
                $errors["templates.{$index}"] = ['Each entry must be an object.'];

                continue;
            }

            foreach (TemplateDocument::flatten($entry) as $record) {
                $problem = $this->reject($record);

                if ($problem !== null) {
                    $errors["templates.{$index}"] = [$problem];

                    continue;
                }

                $records[] = $record;
            }
        }

        if ($errors !== []) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        $created = array_map(fn (array $record): array => $this->create($record), $records);

        return response()->json(['data' => $created], 201);
    }

    /**
     * @param  array{name: string, provider: string, config: array<string, mixed>, persona: array<string, mixed>|null, description: string|null}  $record
     */
    private function reject(array $record): ?string
    {
        if ($record['name'] === '') {
            return 'A template needs a name.';
        }

        if (ProviderFieldSpecs::for($record['provider']) === []) {
            return "Unknown provider '{$record['provider']}'.";
        }

        // The SAME validator the create/edit form is built from (D7). It
        // already refuses unknown keys, so this must not grow a second check:
        // two validators drift, and the divergence would let a file install a
        // config the form would have rejected.
        $failures = ConfigValidator::validate($record['provider'], $record['config']);

        if ($failures === []) {
            return null;
        }

        // The validator reports per key; the import reports per entry, so the
        // messages are flattened into one sentence the operator can act on.
        $flat = [];

        array_walk_recursive($failures, function ($message) use (&$flat): void {
            $flat[] = (string) $message;
        });

        return implode(' ', $flat);
    }

    /**
     * @param  array{name: string, provider: string, config: array<string, mixed>, persona: array<string, mixed>|null, description: string|null, llm: array{model_key: string, credential_name: string}|null}  $record
     * @return array<string, mixed>
     */
    private function create(array $record): array
    {
        $orgId = $this->resolver->getOrgId();

        // Loud, not coerced. On a READ a missing tenant yields an empty result;
        // on a WRITE it would create a row belonging to nobody, which is a
        // tenancy hole that would only surface much later as data an
        // organization cannot see and cannot delete.
        if ($orgId === null) {
            throw new MissingTenantContextException(AvatarTemplate::class);
        }

        [$llmIds, $llmWarnings] = $this->resolveLlmBinding($record['llm']);

        $template = new AvatarTemplate;
        $template->forceFill([
            'organization_id' => $orgId,
            'name' => $this->uniqueName($orgId, $record['name']),
            'description' => $record['description'],
            'provider' => $record['provider'],
            'config' => $record['config'],
            'persona' => $record['persona'],
            // Imports arrive inactive (D8). Activation stays a deliberate act:
            // a file must never change which avatar an organization's live
            // interviews are running on.
            'is_active' => false,
            'llm_model_id' => $llmIds['llm_model_id'],
            'llm_credential_id' => $llmIds['llm_credential_id'],
        ]);
        // I2/I3/I4 still run on THIS save() (design D4/D13) — a mode or
        // vendor mismatch is a 422 via the exception's own render(), not a
        // warning: a file must not install a binding the form would refuse.
        $template->save();

        $result = ['id' => $template->id, 'name' => $template->name, 'provider' => $template->provider];

        if ($llmWarnings !== []) {
            $result['llm_warnings'] = $llmWarnings;
        }

        return $result;
    }

    /**
     * Resolves `{model_key, credential_name}` against the IMPORTING
     * organization — both-or-neither (design D13). I1's CHECK makes "resolve
     * what you can" a shape the database refuses to store, so a partial
     * resolution imports UNBOUND with a warning naming what failed, never as
     * a failed import.
     *
     * @param  array{model_key: string, credential_name: string}|null  $llm
     * @return array{0: array{llm_model_id: int|null, llm_credential_id: int|null}, 1: list<string>}
     */
    private function resolveLlmBinding(?array $llm): array
    {
        if ($llm === null) {
            return [['llm_model_id' => null, 'llm_credential_id' => null], []];
        }

        // Global registry — not tenant-scoped (design D1).
        $model = LlmModel::where('key', $llm['model_key'])->first();
        // Tenant-scoped to the CURRENT (importing) organization via the
        // TenantScoped global scope — never the exporting org's row.
        $credential = LlmCredential::where('name', $llm['credential_name'])->first();

        $warnings = [];

        if ($model === null) {
            $warnings[] = 'model_not_found';
        }

        if ($credential === null) {
            $warnings[] = 'credential_not_found';
        }

        if ($model === null || $credential === null) {
            return [['llm_model_id' => null, 'llm_credential_id' => null], $warnings];
        }

        return [['llm_model_id' => $model->id, 'llm_credential_id' => $credential->id], []];
    }

    /**
     * Never overwrite (D8): a colliding name gets a suffix.
     *
     * Overwriting would silently replace the configuration a live project runs
     * its interviews on, with nothing shown of what was lost. Creating is
     * recoverable.
     */
    private function uniqueName(int $orgId, string $name): string
    {
        $candidate = $name;
        $suffix = 2;

        while (AvatarTemplate::query()
            ->where('organization_id', $orgId)
            ->where('name', $candidate)
            ->exists()
        ) {
            $candidate = "{$name} ({$suffix})";
            $suffix++;
        }

        return $candidate;
    }
}
