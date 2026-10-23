<?php

declare(strict_types=1);

namespace App\Actions\AvatarTemplates;

use App\Models\AvatarTemplate;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContextScope;
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

            foreach (AvatarTemplate::whereIn('provider', self::SOURCE_PROVIDERS)->orderBy('id')->get() as $source) {
                $rows[] = [
                    'line' => sprintf('template=%d provider=%s name="%s"', $source->id, $source->provider, mb_substr($source->name, 0, self::NAME_MAX)),
                    'outcome' => match (true) {
                        $source->hr_avatar_config_id === null => 'skipped not_exported',
                        ! $apply => 'would_twin',
                        default => $this->twin($source),
                    },
                ];
            }

            return $rows;
        });
    }

    /**
     * Creates the INACTIVE twin of one exported source and returns the outcome. Must run inside the organization's
     * tenant context. The source row is locked first so two concurrent cutovers serialize on it.
     */
    private function twin(AvatarTemplate $source): string
    {
        $twin = DB::transaction(function () use ($source): AvatarTemplate {
            AvatarTemplate::whereKey($source->id)->lockForUpdate()->value('id');

            // `config` is literally empty and the LLM binding is left unset: HR-Avatar carries neither. The
            // persona is BEAI's candidate-facing display name, so it is copied. `is_active` stays false.
            return AvatarTemplate::create([
                'name' => DuplicateAvatarTemplate::freeName($source->name.self::TWIN_SUFFIX),
                'description' => $source->description,
                'persona' => $source->persona,
                'answer_min_seconds' => $source->answer_min_seconds,
                'answer_max_seconds' => $source->answer_max_seconds,
                'provider' => 'hr_avatar',
                'config' => [],
                'hr_avatar_config_id' => $source->hr_avatar_config_id,
            ]);
        });

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
}
