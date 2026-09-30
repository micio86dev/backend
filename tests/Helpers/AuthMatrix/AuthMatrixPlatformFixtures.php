<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

use App\Models\Organization;
use Closure;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fixtures of the PLATFORM surfaces (T5): admin, catalogue, framework, LLM
 * credentials/models, avatar templates and M2M client management.
 *
 * Same contract as {@see AuthMatrixFixtures}: a VALID request per route, so a
 * 403 is the authorization decision and never a validation accident. Merged
 * into that registry; kept apart only to keep both files reviewable.
 */
final class AuthMatrixPlatformFixtures
{
    /**
     * @return array<string, array{params?: Closure(AuthMatrixResources): array<string, string|int>, payload?: Closure(AuthMatrixWorld, Organization): array<string, mixed>, before?: Closure(): mixed}>
     */
    public static function registry(): array
    {
        return [
            ...self::admin(),
            ...self::catalogue(),
            ...self::llm(),
            ...self::framework(),
            ...self::avatarTemplates(),
            ...self::m2mClients(),
        ];
    }

    /**
     * @return array<string, array<string, Closure>>
     */
    private static function admin(): array
    {
        $organization = fn (AuthMatrixResources $r): array => ['id' => $r->world()->orgA->id];
        $platformUser = fn (AuthMatrixResources $r): array => ['id' => $r->platform()->platformUser()->id];

        return [
            'PUT api/admin/acting-organization' => [
                'payload' => fn (AuthMatrixWorld $w): array => ['organization_id' => $w->orgA->id],
            ],
            'GET api/admin/clients' => [],
            'GET api/admin/organizations' => [],
            'POST api/admin/organizations' => [
                'payload' => function (): array {
                    $slug = 'matrix-'.Str::lower(Str::random(8));

                    return ['name' => 'Organization created by the matrix', 'slug' => $slug];
                },
            ],
            'GET api/admin/organizations/{id}' => ['params' => $organization],
            'PATCH api/admin/organizations/{id}' => [
                'params' => $organization,
                'payload' => fn (): array => ['name' => 'Renamed by the matrix'],
            ],
            'GET api/admin/platform-users' => [],
            'POST api/admin/platform-users' => [
                'payload' => fn (): array => [
                    'name' => 'Platform user created by the matrix',
                    'email' => Str::lower(Str::random(8)).'@matrix.test',
                    'password' => 'a-long-enough-password',
                ],
            ],
            'PATCH api/admin/platform-users/{id}' => [
                'params' => $platformUser,
                'payload' => fn (): array => ['name' => 'Renamed by the matrix'],
            ],
            'POST api/admin/platform-users/{id}/deactivate' => ['params' => $platformUser],
            'POST api/admin/platform-users/{id}/activate' => [
                'params' => fn (AuthMatrixResources $r): array => ['id' => $r->platform()->deactivatedPlatformUser()->id],
            ],
            'GET api/admin/settings' => [],
            'PATCH api/admin/settings' => [
                'payload' => fn (): array => ['max_questions_per_competency' => ['standard' => 3]],
            ],
        ];
    }

    /**
     * @return array<string, array<string, Closure>>
     */
    private static function catalogue(): array
    {
        $role = fn (AuthMatrixResources $r): array => ['role' => $r->platform()->role()->id];
        $competency = fn (AuthMatrixResources $r): array => ['competency' => $r->platform()->competency()->id];
        $indicator = fn (AuthMatrixResources $r): array => ['indicator' => $r->platform()->indicator()->id];
        $question = fn (AuthMatrixResources $r): array => ['defaultQuestion' => $r->platform()->defaultQuestion()->id];
        // A write to an OPEN draft needs one to exist, or the request answers 404 for a reason
        // unrelated to authorization. Collection writes open their own draft.
        $openDraft = fn (AuthMatrixWorld $w): array => tap([], fn () => $w->platform()->draft());
        $localized = fn (string $en): array => ['en' => $en, 'it' => $en];

        return [
            'GET api/catalogue/revisions/current' => [],
            'POST api/catalogue/revisions/draft' => [],
            'DELETE api/catalogue/revisions/draft' => ['payload' => $openDraft],
            // The draft is empty on purpose (see AuthMatrixPlatformResources::draft()), so the
            // publish sweep finds nothing to refuse and the flip really happens.
            'POST api/catalogue/revisions/publish' => ['payload' => $openDraft],

            'GET api/catalogue/roles' => [],
            'POST api/catalogue/roles' => [
                'payload' => fn (): array => ['code' => 'MXROLE', 'name' => $localized('Role created by the matrix')],
            ],
            'PATCH api/catalogue/roles/{role}' => [
                'params' => $role,
                'payload' => fn (): array => ['name' => $localized('Renamed by the matrix')],
            ],
            'DELETE api/catalogue/roles/{role}' => ['params' => $role],
            'PUT api/catalogue/roles/{role}/competencies' => [
                'params' => $role,
                'payload' => fn (AuthMatrixWorld $w): array => ['competency_ids' => [$w->platform()->competency()->id]],
            ],

            'GET api/catalogue/competencies' => [],
            'POST api/catalogue/competencies' => [
                'payload' => fn (): array => [
                    'code' => 'MXCOMP',
                    'type' => 'standard',
                    'name' => $localized('Competency created by the matrix'),
                    'definition' => $localized('Defined by the matrix'),
                ],
            ],
            'PATCH api/catalogue/competencies/{competency}' => [
                'params' => $competency,
                'payload' => fn (): array => ['name' => $localized('Renamed by the matrix')],
            ],
            'DELETE api/catalogue/competencies/{competency}' => ['params' => $competency],

            'GET api/catalogue/bars-indicators' => [],
            'POST api/catalogue/bars-indicators' => [
                'payload' => fn (AuthMatrixWorld $w): array => [
                    'competency_id' => $w->platform()->competency()->id,
                    'position' => 5,
                    'text' => $localized('Indicator created by the matrix'),
                    'anchor_5' => $localized('Five'),
                    'anchor_3' => $localized('Three'),
                    'anchor_1' => $localized('One'),
                ],
            ],
            'PATCH api/catalogue/bars-indicators/{indicator}' => [
                'params' => $indicator,
                'payload' => fn (): array => ['text' => $localized('Edited by the matrix')],
            ],
            'DELETE api/catalogue/bars-indicators/{indicator}' => ['params' => $indicator],

            'GET api/catalogue/default-questions' => [],
            'POST api/catalogue/default-questions' => [
                'payload' => fn (AuthMatrixWorld $w): array => [
                    'competency_id' => $w->platform()->competency()->id,
                    'position' => 5,
                    'text' => $localized('Question created by the matrix'),
                ],
            ],
            'PATCH api/catalogue/default-questions/{defaultQuestion}' => [
                'params' => $question,
                'payload' => fn (): array => ['text' => $localized('Edited by the matrix')],
            ],
            'DELETE api/catalogue/default-questions/{defaultQuestion}' => ['params' => $question],
        ];
    }

    /**
     * @return array<string, array<string, Closure>>
     */
    private static function llm(): array
    {
        $credential = fn (AuthMatrixResources $r): array => ['id' => $r->platform()->llmCredential()->id];
        // Store and update both validate the key against Google: an allowed call must not leave the process.
        $googleAcceptsKey = fn () => Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 200)]);

        return [
            'GET api/llm-credentials' => [],
            'POST api/llm-credentials' => [
                'before' => $googleAcceptsKey,
                'payload' => fn (): array => [
                    'name' => 'Credential created by the matrix '.Str::random(6),
                    'vendor' => 'google',
                    'api_key' => 'matrix-secret-key-'.Str::random(8),
                ],
            ],
            'GET api/llm-credentials/{id}' => ['params' => $credential],
            'PATCH api/llm-credentials/{id}' => [
                'before' => $googleAcceptsKey,
                'params' => $credential,
                'payload' => fn (): array => ['name' => 'Renamed by the matrix '.Str::random(6)],
            ],
            'DELETE api/llm-credentials/{id}' => ['params' => $credential],
            'GET api/llm-models' => [],
        ];
    }

    /**
     * @return array<string, array<string, Closure>>
     */
    private static function framework(): array
    {
        // The role/competency routes resolve ICO / PRS on the latest PUBLISHED revision,
        // which is empty in the test database until the catalogue is seeded.
        $seeded = fn () => (new FrameworkCatalogSeeder)->run();

        return [
            'GET api/framework/roles' => ['before' => $seeded],
            'GET api/framework/roles/{roleCode}/competencies' => ['before' => $seeded, 'params' => fn (): array => ['roleCode' => 'ICO']],
            'GET api/framework/roles/{roleCode}/competencies/{competencyCode}/indicators' => ['before' => $seeded, 'params' => fn (): array => ['roleCode' => 'ICO', 'competencyCode' => 'PRS']],
            'GET api/framework/potential-competencies' => ['before' => $seeded],
            'GET api/framework/versions' => [],
        ];
    }

    /**
     * @return array<string, array<string, Closure>>
     */
    private static function avatarTemplates(): array
    {
        $template = fn (AuthMatrixResources $r): array => ['id' => $r->platform()->template()->id];
        $config = fn (): array => ['avatarId' => 'av_'.Str::random(6), 'voiceId' => 'vo_'.Str::random(6)];

        $global = fn (AuthMatrixResources $r): array => ['id' => $r->platform()->globalTemplate()->id];

        return [
            'GET api/admin/avatar-templates' => [],
            'POST api/admin/avatar-templates' => [
                'payload' => fn (): array => [
                    'name' => 'Platform template created by the matrix '.Str::random(6),
                    'provider' => 'heygen',
                    'config' => $config(),
                ],
            ],
            'GET api/admin/avatar-templates/{id}' => ['params' => $global],
            'PATCH api/admin/avatar-templates/{id}' => [
                'params' => $global,
                'payload' => fn (): array => ['name' => 'Platform template renamed by the matrix '.Str::random(6)],
            ],
            'POST api/admin/avatar-templates/{id}/activate' => ['params' => $global],
            'POST api/admin/avatar-templates/{id}/deactivate' => [
                'params' => fn (AuthMatrixResources $r): array => ['id' => $r->platform()->activeGlobalTemplate()->id],
            ],
            'GET api/avatar-templates' => [],
            'POST api/avatar-templates' => [
                'payload' => fn () => [
                    'name' => 'Template created by the matrix '.Str::random(6),
                    'provider' => 'heygen',
                    'config' => $config(),
                ],
            ],
            // The catalogue proxies the provider's voice list: an allowed call must not reach the network.
            'GET api/avatar-templates/catalogue' => [
                'before' => function (): void {
                    config(['interview.heygen.api_key' => 'TEST_HEYGEN_KEY']);
                    Http::fake(['*' => Http::response(['code' => 100, 'data' => ['count' => 0, 'results' => []], 'message' => 'ok'], 200)]);
                },
                'payload' => fn (): array => ['provider' => 'heygen', 'resource' => 'voice'],
            ],
            'GET api/avatar-templates/export' => [],
            'GET api/avatar-templates/field-specs' => [],
            'POST api/avatar-templates/import' => [
                'payload' => fn () => [
                    'schema' => 'beai.avatar-template/1',
                    'templates' => [[
                        'name' => 'Template imported by the matrix '.Str::random(6),
                        'provider' => 'heygen',
                        'config' => $config(),
                    ]],
                ],
            ],
            // A synthesised sample is a paid provider call: an allowed cell must neither reach the
            // network nor write to the real disk.
            'POST api/avatar-templates/voice-preview' => [
                'before' => function (): void {
                    Storage::fake();
                    config(['services.cartesia.api_key' => 'TEST_CARTESIA_KEY']);
                    Http::fake(['*' => Http::response("ID3\x03\x00\x00\x00\x00\x00\x00MATRIX", 200)]);
                },
                'payload' => fn (): array => ['provider' => 'cartesia', 'voice_id' => 'matrix-voice'],
            ],
            'GET api/avatar-templates/options' => [],
            'GET api/avatar-templates/{id}' => ['params' => $template],
            'PATCH api/avatar-templates/{id}' => [
                'params' => $template,
                'payload' => fn (): array => ['name' => 'Renamed by the matrix '.Str::random(6)],
            ],
            'DELETE api/avatar-templates/{id}' => ['params' => $template],
            'POST api/avatar-templates/{id}/activate' => ['params' => $template],
            // Copies into orgB (never the source's own org), so an allowed cell really creates a row.
            'POST api/avatar-templates/{id}/duplicate' => [
                'params' => $template,
                'payload' => fn (AuthMatrixWorld $w): array => ['target_organization_ids' => [$w->orgB->id]],
            ],
            'POST api/avatar-templates/{id}/deactivate' => [
                'params' => fn (AuthMatrixResources $r): array => ['id' => $r->platform()->activeTemplate()->id],
            ],
        ];
    }

    /**
     * @return array<string, array<string, Closure>>
     */
    private static function m2mClients(): array
    {
        return [
            'GET api/m2m/abilities' => [],
            'GET api/m2m/clients' => [],
            'POST api/m2m/clients' => [
                'payload' => fn (): array => [
                    'name' => 'Client created by the matrix',
                    'abilities' => ['participants:read'],
                ],
            ],
            'DELETE api/m2m/clients/{apiClient}' => [
                'params' => fn (AuthMatrixResources $r): array => ['apiClient' => $r->platform()->apiClient()->id],
            ],
        ];
    }
}
