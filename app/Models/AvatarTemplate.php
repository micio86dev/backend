<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LlmMode;
use App\Exceptions\ConversationLlm\InvalidLlmBindingException;
use App\Exceptions\ConversationLlm\UnsupportedLlmModeException;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * `organization_id` is NOT fillable. It is stamped by TenantScoped from the
 * resolver, never from a payload — the same invariant Participant and User
 * carry, for the same reason.
 *
 * @property int $id
 * @property int $organization_id
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
 */
class AvatarTemplate extends TenantModel
{
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

            // I3 — the credential belongs to the template's org, compared
            // EXPLICITLY against an UNSCOPED read. The tenant global scope
            // has a documented superadmin bypass (TenantScoped.php) and is
            // therefore NOT an authorization check.
            //
            // GOTCHA: `saving` fires BEFORE `creating`, so on an INSERT the
            // tamper-proof organization_id stamp has NOT run yet — derive
            // the owning org from the resolver instead. On an UPDATE,
            // getOriginal() is the PERSISTED value, so a forceFill() of
            // organization_id cannot move the goalposts mid-check.
            $ownerOrgId = $template->exists
                ? $template->getOriginal('organization_id')
                : app(TenantResolver::class)->getOrgId();

            if ($ownerOrgId === null) {
                throw new MissingTenantContextException(self::class);
            }

            $credential = LlmCredential::withoutGlobalScopes()->find($template->llm_credential_id);

            // One code for both outcomes, on purpose: "no such credential"
            // and "someone else's credential" must not be distinguishable —
            // that would make this an existence oracle (the same 404-not-403
            // doctrine D9 states elsewhere, applied to a 422 body here).
            if ($credential === null || $credential->organization_id !== $ownerOrgId) {
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
}
