<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AvatarTemplateScope;
use App\Enums\LlmMode;
use App\Exceptions\AvatarTemplateInUseException;
use App\Exceptions\ConversationLlm\InvalidLlmBindingException;
use App\Exceptions\ConversationLlm\UnsupportedLlmModeException;
use App\Exceptions\PlatformTemplateWriteRefusedException;
use App\Models\Contracts\AdmitsPlatformRows;
use App\Support\AvatarTemplates\PlatformTemplateContext;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A named avatar/voice configuration an operator can activate (C14).
 *
 * Exactly one template per organization, PER PROVIDER, may be active at a
 * time (pluggable-conversation-llm PR P0), and that is enforced by a partial
 * unique index — `avatar_templates_one_active_per_org_provider` — rather than
 * by this class. Two concurrent activations both read "nobody else is active
 * on this provider", both write, and both win; a check that only holds when
 * nobody is in a hurry is not a check. Application code deactivates first for
 * a clean user experience, and the index is what makes the invariant true.
 *
 * PLATFORM ROWS. `organization_id IS NULL` marks a template owned by the
 * platform. Two always-on scopes keep such a row out of every query by
 * default: the strict `tenant` scope (which filters nothing under superadmin
 * bypass) and `exclude_platform_rows` (which does, in every context). A row is
 * reachable only through the two named scopes below, whose call sites are
 * pinned by an architecture test.
 *
 * `organization_id` is NOT fillable. It is stamped by TenantScoped from the
 * resolver, never from a payload — the same invariant Participant and User
 * carry, for the same reason.
 *
 * @property int $id
 * @property int|null $organization_id NULL marks a platform (global) template
 * @property string $name
 * @property string|null $description
 * @property string $provider
 * @property array<string, mixed> $config
 * @property array<string, mixed>|null $persona
 * @property bool $is_active
 * @property int|null $llm_model_id
 * @property int|null $llm_credential_id
 * @property string|null $heygen_llm_configuration_id
 * @property string|null $llm_sync_status
 * @property Carbon|null $llm_synced_at
 * @property string|null $pal_sync_status
 * @property string|null $pal_sync_code
 * @property Carbon|null $pal_synced_at
 */
class AvatarTemplate extends TenantModel implements AdmitsPlatformRows
{
    use SoftDeletes;

    /** Hides platform (NULL-organization) rows from every default query. */
    public const SCOPE_EXCLUDE_PLATFORM_ROWS = 'exclude_platform_rows';

    /**
     * Mirrors the database defaults IN MEMORY.
     *
     * Without this a freshly created instance carries `is_active = null` until
     * something reloads it — so `if ($template->is_active)` reads false for the
     * right answer and the wrong reason, and the day the column default changes
     * nothing here notices.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => false,
        'config' => '{}',
    ];

    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
        'provider',
        'config',
        'persona',
        'is_active',
        // Both-or-neither, enforced by a DB CHECK (I1) and by booted()'s I2/
        // I3/I4 guards below (pluggable-conversation-llm PR P3a, design D4).
        'llm_model_id',
        'llm_credential_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Without this the column round-trips as a JSON STRING, and every
            // downstream read sees "no avatar configured" — a failure that
            // reads as a missing setting rather than a broken cast.
            'config' => 'array',
            'persona' => 'array',
            'is_active' => 'boolean',
            'llm_model_id' => 'integer',
            'llm_credential_id' => 'integer',
            'llm_synced_at' => 'datetime',
            'pal_synced_at' => 'datetime',
        ];
    }

    /**
     * Binding invariants I2/I3/I4/I5 (pluggable-conversation-llm PR P3a,
     * design D4; I5 added closing an adversarial-review gap: `is_available`
     * was carried on `LlmModel` but never enforced on any write path).
     * Enforced on `saving` — the one write hook `forceFill()` (the
     * portability import path) cannot dodge — never only in a FormRequest.
     */
    protected static function booted(): void
    {
        // NOT optional. TenantModel registers TenantScoped's global scope
        // here; declaring booted() without this call would silently
        // unregister it on the single model this entire change hangs off.
        parent::booted();

        // Second, independent scope: under superadmin bypass the `tenant`
        // scope applies no filter at all, so without this every org route,
        // bulk update and demo path would see (and could mutate) platform
        // rows whenever a bare superadmin runs it.
        static::addGlobalScope(
            self::SCOPE_EXCLUDE_PLATFORM_ROWS,
            fn (Builder $query) => $query->whereNotNull($query->getModel()->getTable().'.organization_id'),
        );

        // Write guards: `(organization_id IS NULL) === platform context active`,
        // in both directions, so the platform context can never write a tenant
        // row and a tenant context can never write a platform row. `creating`
        // is registered HERE so it runs after TenantScoped's stamp and sees the
        // final organization. Quiet writes (`saveQuietly`) skip events on
        // purpose: provider bookkeeping must be able to stamp a platform row
        // from any context.
        static::creating(fn (self $template) => $template->assertWriteSide($template->organization_id, 'create'));

        static::updating(function (self $template): void {
            if ($template->isDirty('organization_id')) {
                throw new PlatformTemplateWriteRefusedException('move');
            }

            $template->assertWriteSide($template->getOriginal('organization_id'), 'update');
        });

        static::restoring(fn (self $template) => $template->assertWriteSide($template->organization_id, 'restore'));

        static::deleting(function (self $template): void {
            $template->assertWriteSide($template->organization_id, 'delete');

            // A FORCE delete is still governed by the foreign key itself —
            // `restrictOnDelete` sees every project row, trashed ones
            // included, and refuses. Duplicating that here would only add a
            // second, weaker copy of a rule the database already holds.
            if ($template->isForceDeleting()) {
                return;
            }

            // Trashed projects are deliberately NOT counted. They are already
            // invisible, so hiding their template breaks nothing today, and a
            // restore can restore the template alongside. Counting them would
            // make a template undeletable forever because of a project nobody
            // can see — and it would disagree with the count the controller
            // reports, so the operator would read "0 projects" and still be
            // refused.
            // The tenant scope is dropped, the SOFT-DELETE scope deliberately
            // is not: a template is deleted from within its own tenant context
            // anyway, and dropping every scope would silently start counting
            // trashed projects — the opposite of what the comment above says.
            $projectCount = Project::withoutGlobalScope('tenant')
                ->where('avatar_template_id', $template->id)
                ->count();

            if ($projectCount > 0) {
                throw new AvatarTemplateInUseException($projectCount);
            }
        });

        static::deleted(function (self $template): void {
            if ($template->isForceDeleting()) {
                return;
            }

            // A soft-deleted template keeps its ROW, and its row keeps its
            // `llm_credential_id` — which `llm_credentials` references with
            // ON DELETE RESTRICT. The credential-deletion guard counts BOUND
            // templates to decide whether removing a credential is safe, and
            // that count applies the soft-delete scope while the foreign key
            // does not. The two disagreed: the guard saw nothing, allowed the
            // delete, and Postgres refused it as an unhandled 500.
            //
            // The binding is torn down at the provider before the template is
            // deleted (HeygenLlmRegistrar::forget), so it is already dead by
            // the time we get here — nulling it makes the row agree with
            // reality rather than inventing a new rule. Both columns go
            // together because I1's CHECK constraint refuses a half-bound row.
            //
            // Written through the query builder deliberately: `runSoftDelete`
            // updates ONLY `deleted_at`, so dirty attributes set in a
            // `deleting` hook are silently dropped, and this has to happen
            // after the row is already marked deleted.
            static::withoutGlobalScopes()
                ->whereKey($template->getKey())
                ->update(['llm_model_id' => null, 'llm_credential_id' => null]);

            $template->llm_model_id = null;
            $template->llm_credential_id = null;
            $template->syncOriginalAttributes(['llm_model_id', 'llm_credential_id']);
        });

        static::saving(function (self $template): void {
            // Unbound is always legal; I1's CHECK handles the half-bound
            // case at the database. Nothing to check here.
            if ($template->llm_model_id === null && $template->llm_credential_id === null) {
                return;
            }

            $model = LlmModel::find($template->llm_model_id);

            if ($model === null) {
                throw new InvalidLlmBindingException('llm_model_id', 'model_not_found');
            }

            // I5 — a withdrawn (`is_available = false`) model cannot be
            // NEWLY bound. Gated on `isDirty('llm_model_id')` deliberately:
            // a template already bound to a model that later becomes
            // unavailable MUST keep saving for unrelated field changes
            // (renaming it, changing its voice settings) — that is the
            // entire point of "mark unavailable, never delete" (design D1).
            // Rejecting every unrelated edit to a grandfathered template
            // would be a worse bug than the one this guard fixes.
            if ($template->isDirty('llm_model_id') && ! $model->is_available) {
                throw new InvalidLlmBindingException('llm_model_id', 'model_unavailable');
            }

            // I2 — native_duplex is refused at every write path.
            if ($model->capability->mode() !== LlmMode::Managed) {
                throw new UnsupportedLlmModeException('llm_model_id');
            }

            // I3 — the credential must EXIST. It no longer has to belong to
            // anyone: credentials became platform rows (RATIFIED 2026-09-14),
            // so the ownership half of this guard was refusing the only
            // arrangement the product now has.
            //
            // What went with it, and why none of it is a loss:
            //   - the `$ownerOrgId` derivation, including the `saving`-fires-
            //     before-`creating` gotcha it existed to dodge — nothing
            //     compares against an owning org any more;
            //   - the `MissingTenantContextException` on a null org, which was
            //     protecting that comparison and not the binding;
            //   - `withoutGlobalScopes()`, which defeated a tenant scope
            //     `LlmCredential` no longer carries.
            //
            // The existence-oracle concern that shaped the original message is
            // also moot — there is one visible set of credentials now, so "no
            // such credential" leaks nothing about anybody else. The code is
            // kept verbatim because it is a published 422 body.
            $credential = LlmCredential::find($template->llm_credential_id);

            if ($credential === null) {
                throw new InvalidLlmBindingException('llm_credential_id', 'credential_not_found');
            }

            // I4 — the credential's vendor must match the model's vendor.
            if ($credential->vendor !== $model->vendor) {
                throw new InvalidLlmBindingException('llm_credential_id', 'vendor_mismatch');
            }
        });
    }

    /** @return BelongsTo<LlmModel, $this> */
    public function llmModel(): BelongsTo
    {
        return $this->belongsTo(LlmModel::class);
    }

    /** @return BelongsTo<LlmCredential, $this> */
    public function llmCredential(): BelongsTo
    {
        return $this->belongsTo(LlmCredential::class);
    }

    /**
     * Own rows PLUS platform rows: the read path for anything that must
     * resolve a template a project may pin (runtime reads, the picker).
     *
     * The organization comes from the resolver, never from an argument, so
     * there is nothing to pass wrongly. With no context at all it FAILS
     * CLOSED: `organization_id = NULL OR organization_id IS NULL` would hand
     * every platform row to a caller that established no tenant.
     *
     * NOTE: `has()` / `whereHas()` on a relation using this scope merge the
     * removed scopes back in; no such call exists today.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAvailableToTenant(Builder $query): Builder
    {
        $query->withoutGlobalScopes(['tenant', self::SCOPE_EXCLUDE_PLATFORM_ROWS]);

        $resolver = app(TenantResolver::class);
        $column = $query->getModel()->getTable().'.organization_id';

        if ($resolver->isBypass()) {
            return $query;
        }

        $orgId = $resolver->getOrgId();

        if ($orgId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q->where($column, $orgId)->orWhereNull($column));
    }

    /**
     * Platform rows only, in any context.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePlatformOnly(Builder $query): Builder
    {
        return $query
            ->withoutGlobalScopes(['tenant', self::SCOPE_EXCLUDE_PLATFORM_ROWS])
            ->whereNull($query->getModel()->getTable().'.organization_id');
    }

    public function isPlatform(): bool
    {
        return $this->organization_id === null;
    }

    public function scopeLabel(): AvatarTemplateScope
    {
        return $this->isPlatform() ? AvatarTemplateScope::Platform : AvatarTemplateScope::Organization;
    }

    public function writesAsPlatformRow(): bool
    {
        return app(PlatformTemplateContext::class)->active();
    }

    private function assertWriteSide(mixed $organizationId, string $action): void
    {
        if (($organizationId === null) !== $this->writesAsPlatformRow()) {
            throw new PlatformTemplateWriteRefusedException($action);
        }
    }
}
