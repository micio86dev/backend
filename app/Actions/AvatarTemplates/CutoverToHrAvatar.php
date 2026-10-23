<?php

declare(strict_types=1);

namespace App\Actions\AvatarTemplates;

use App\Models\AvatarTemplate;
use App\Services\Provider\HrAvatarConnection;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The per-organization work of `hr-avatar:cutover`.
 *
 * Everything runs inside `TenantContextScope::runFor($organizationId)` through the ordinary tenant-scoped
 * Eloquent queries: no global scope is stripped, so a lookup can only ever match the current organization's live,
 * organization-owned rows (platform and soft-deleted rows are out by construction).
 *
 * Rows are plain arrays: `outcome`'s first token is the kind the command counts.
 */
final class CutoverToHrAvatar
{
    private const SOURCE_PROVIDERS = ['tavus', 'heygen'];

    private const NAME_MAX = 120;

    public const TWIN_SUFFIX = ' (HR-Avatar)';

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return list<array{line: string, outcome: string}>
     */
    public function cutover(int $organizationId, bool $apply): array
    {
        return TenantContextScope::runFor($organizationId, function () use ($apply): array {
            $rows = [];
            $sources = AvatarTemplate::whereIn('provider', self::SOURCE_PROVIDERS)->orderBy('id')->get();
            // Two live sources on one config id (possible through `DuplicateAvatarTemplate`, which copies the id)
            // would make the source<->twin pairing ambiguous, so neither is twinned.
            $sharedIds = array_keys(array_filter(array_count_values($sources->pluck('hr_avatar_config_id')->filter()->all()), fn (int $count): bool => $count > 1));

            foreach ($sources as $source) {
                $rows[] = [
                    'line' => sprintf('template=%d provider=%s name="%s"', $source->id, $source->provider, mb_substr($source->name, 0, self::NAME_MAX)),
                    'outcome' => match (true) {
                        $source->hr_avatar_config_id === null => 'skipped not_exported',
                        ! HrAvatarConnection::isConfigId($source->hr_avatar_config_id) => 'failed config_id_invalid',
                        in_array($source->hr_avatar_config_id, $sharedIds, true) => 'failed config_shared',
                        ! $apply => ($existing = $this->findTwin($source)) === null ? 'would_twin' : "reused twin={$existing->id}",
                        default => $this->twin($source),
                    },
                ];
            }

            return $rows;
        });
    }

    /**
     * The twin already linked to the source's HR-Avatar config in this organization: a manually created `hr_avatar`
     * row with the same config id is semantically identical and is reused too. The tenant scopes keep the lookup
     * inside the organization's live rows, so another organization's row is never matched.
     */
    private function findTwin(AvatarTemplate $source): ?AvatarTemplate
    {
        return AvatarTemplate::where('provider', 'hr_avatar')
            ->where('hr_avatar_config_id', $source->hr_avatar_config_id)
            ->orderBy('id')
            ->first();
    }

    /**
     * Finds or creates the INACTIVE twin of one exported source and returns the outcome. Must run inside the
     * organization's tenant context. The source row is locked first, so two concurrent cutovers serialize on it and
     * the lookup that follows cannot miss a twin the other one is creating.
     */
    private function twin(AvatarTemplate $source): string
    {
        try {
            [$twin, $created] = $this->findOrCreateTwin($source);
        } catch (UniqueConstraintViolationException) {
            // A concurrent write took the name between the free-name check and the insert (the partial unique
            // index on `(organization_id, name)`): the transaction rolled back, a re-run picks a new name.
            return 'failed write_conflict';
        }

        if (! $created) {
            return "reused twin={$twin->id}";
        }

        // After the commit, like `DuplicateAvatarTemplate`: the recorder never throws, so a failed audit can
        // neither abort the write nor be recorded for a rolled-back one.
        $this->audit->record('avatar_template.hr_avatar_twin_created', 'avatar_template', $twin->id, after: [
            'name' => $twin->name,
            'provider' => 'hr_avatar',
            'source_template_id' => $source->id,
            'hr_avatar_config_id' => $twin->hr_avatar_config_id,
        ]);

        return "twinned twin={$twin->id}";
    }

    /**
     * @return array{0: AvatarTemplate, 1: bool} the twin and whether this call created it
     */
    private function findOrCreateTwin(AvatarTemplate $source): array
    {
        return DB::transaction(function () use ($source): array {
            AvatarTemplate::whereKey($source->id)->lockForUpdate()->value('id');

            $existing = $this->findTwin($source);

            if ($existing !== null) {
                return [$existing, false];
            }

            // `config` is literally empty and the LLM binding is left unset: HR-Avatar carries neither. The
            // persona is BEAI's candidate-facing display name, so it is copied. `is_active` stays false.
            return [AvatarTemplate::create([
                'name' => DuplicateAvatarTemplate::freeName(mb_substr($source->name, 0, self::NAME_MAX - mb_strlen(self::TWIN_SUFFIX)).self::TWIN_SUFFIX),
                'description' => $source->description,
                'persona' => $source->persona,
                'answer_min_seconds' => $source->answer_min_seconds,
                'answer_max_seconds' => $source->answer_max_seconds,
                'provider' => 'hr_avatar',
                'config' => [],
                'hr_avatar_config_id' => $source->hr_avatar_config_id,
            ]), true];
        });
    }
}
