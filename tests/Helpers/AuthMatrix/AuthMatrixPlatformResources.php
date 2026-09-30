<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

use App\Actions\Catalogue\OpenDraftRevision;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkDefaultQuestion;
use App\Models\LlmCredential;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContextScope;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;

/**
 * The target resources of the PLATFORM and avatar-template surfaces of a
 * matrix case (T5), built lazily and memoised like {@see AuthMatrixResources}.
 *
 *   - platform users, the catalogue draft (and its content), LLM credentials:
 *     GLOBAL rows, owned by nobody;
 *   - avatar templates and M2M clients: rows of `orgA`, the target tenant.
 *
 * Every row carries the world's marker in a human-readable field, so the
 * runner can prove a denied response leaked nothing about it.
 */
final class AuthMatrixPlatformResources
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly AuthMatrixWorld $world) {}

    /**
     * An ACTIVE platform user other than the caller: whom a superadmin edits or deactivates.
     */
    public function platformUser(): User
    {
        return $this->memo['platformUser'] ??= $this->newPlatformUser('platform user');
    }

    /**
     * A DEACTIVATED platform user: whom a superadmin may bring back.
     */
    public function deactivatedPlatformUser(): User
    {
        return $this->memo['deactivatedPlatformUser'] ??= (function (): User {
            $user = $this->newPlatformUser('deactivated platform user');
            $user->forceFill(['deactivated_at' => now()])->save();

            return $user->refresh();
        })();
    }

    private function newPlatformUser(string $label): User
    {
        return User::factory()->create([
            'organization_id' => null,
            'is_superadmin' => true,
            'name' => "{$this->world->marker} {$label}",
        ]);
    }

    /**
     * The open DRAFT catalogue revision (cloned from the published baseline,
     * which is empty in the test database). Deliberately holds no content:
     * a draft with an indicator that no role declares would fail the publish
     * sweep, and `POST /revisions/publish` must be able to succeed.
     */
    public function draft(): FrameworkCatalogRevision
    {
        return $this->memo['draft'] ??= app(OpenDraftRevision::class)->open();
    }

    public function role(): Role
    {
        return $this->memo['role'] ??= Role::factory()->create([
            'revision_id' => $this->draft()->id,
            'code' => 'AMXR',
            'name' => ['en' => "{$this->world->marker} role"],
        ]);
    }

    public function competency(): Competency
    {
        return $this->memo['competency'] ??= Competency::factory()->create([
            'revision_id' => $this->draft()->id,
            'code' => 'AMXC',
            'name' => ['en' => "{$this->world->marker} competency"],
        ]);
    }

    /**
     * A role-less indicator of the draft competency. Content is added only by
     * the routes that need it, so the publish case keeps a clean draft.
     */
    public function indicator(): BarsIndicator
    {
        return $this->memo['indicator'] ??= BarsIndicator::factory()->roleLess()->create([
            'revision_id' => $this->draft()->id,
            'competency_id' => $this->competency()->id,
            'text' => ['en' => "{$this->world->marker} indicator"],
            'position' => 0,
        ]);
    }

    public function defaultQuestion(): FrameworkDefaultQuestion
    {
        return $this->memo['defaultQuestion'] ??= FrameworkDefaultQuestion::factory()->create([
            'competency_id' => $this->competency()->id,
            'revision_id' => $this->draft()->id,
            'text' => ['en' => "{$this->world->marker} question", 'it' => "{$this->world->marker} domanda"],
            'position' => 0,
        ]);
    }

    /**
     * An INACTIVE, deletable template of orgA: nothing references it.
     */
    public function template(): AvatarTemplate
    {
        return $this->memo['template'] ??= $this->newTemplate('template', false);
    }

    /**
     * The ACTIVE template of orgA, which `deactivate` switches off.
     */
    public function activeTemplate(): AvatarTemplate
    {
        return $this->memo['activeTemplate'] ??= $this->newTemplate('active template', true);
    }

    /**
     * A platform (global) template: NULL organization, owned by nobody. Raw
     * insert, so building the fixture never depends on the write guards.
     */
    public function globalTemplate(): AvatarTemplate
    {
        return $this->memo['globalTemplate'] ??= PlatformTemplates::insertGlobal([
            'name' => "{$this->world->marker} global template",
        ]);
    }

    /**
     * An OFFERED platform template, which `deactivate` retires.
     */
    public function activeGlobalTemplate(): AvatarTemplate
    {
        return $this->memo['activeGlobalTemplate'] ??= PlatformTemplates::insertActiveGlobal([
            'name' => "{$this->world->marker} offered global template",
        ]);
    }

    private function newTemplate(string $label, bool $active): AvatarTemplate
    {
        return TenantContextScope::runFor($this->world->orgA->id, fn (): AvatarTemplate => AvatarTemplate::create([
            'name' => "{$this->world->marker} {$label}",
            'provider' => 'heygen',
            'config' => ['avatarId' => 'av_matrix', 'voiceId' => 'vo_matrix'],
            'is_active' => $active,
        ]));
    }

    public function llmCredential(): LlmCredential
    {
        return $this->memo['llmCredential'] ??= LlmCredential::create([
            'name' => "{$this->world->marker} credential",
            'vendor' => 'google',
            'api_key' => 'matrix-secret-key-0001',
            'key_last_four' => '0001',
            'key_fingerprint' => hash('sha256', 'matrix-secret-key-0001'),
        ]);
    }

    /**
     * A live M2M client of orgA — the key an admin revokes.
     */
    public function apiClient(): ApiClient
    {
        return $this->memo['apiClient'] ??= ApiClient::factory()->create([
            'organization_id' => $this->world->orgA->id,
            'name' => "{$this->world->marker} client",
        ]);
    }
}
