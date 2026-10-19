<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AvatarTemplate;
use App\Services\Provider\HrAvatarConnection;
use App\Services\Provider\HrAvatarTemplateMapping;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prepares one HR-Avatar avatar config per existing Tavus or HeyGen template and links it by writing
 * `avatar_templates.hr_avatar_config_id`, and nothing else.
 *
 * Dry run by default: without `--apply` no request is made and no row is written, and it works while the
 * integration is unavailable. A deliberate command rather than a migration: it talks to a third party with
 * the platform's secret key, so an operator decides when it runs and reads what it did.
 *
 * What is NOT transferred: provider API keys, the language model binding (`llm_*`), the BEAI persona column. The
 * template config is translated by `HrAvatarTemplateMapping` into HR-Avatar's `config`, `persona` and `voice`; a key
 * with no counterpart is never sent and is printed as `dropped_key=<keys>` (and counted), so nothing is lost silently.
 * An external voice engine without its voice id is `failed voice_missing`: nothing is created, because the config
 * would speak with another voice.
 *
 * An unreachable upstream is `provider_unreachable_ambiguous`: the POST may have created the config. HR-Avatar's
 * create body carries no per-template marker (`external_ref` is the organization, and a name is neither unique
 * upstream nor stable), so a pre-check could link a stranger's config. The operator is told what to check instead.
 */
final class ExportTemplatesToHrAvatar extends Command
{
    protected $signature = 'hr-avatar:export-templates {--apply : Create the configs and link the templates (default: dry run)}';

    protected $description = 'Create an HR-Avatar config for each Tavus/HeyGen template and link it (dry run unless --apply)';

    private const PROVIDERS = ['tavus', 'heygen'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && ! HrAvatarConnection::isAvailable()) {
            $this->error('HR-Avatar is not configured. Missing: '.implode(', ', HrAvatarConnection::missingVariables()).'. Nothing was changed.');

            return self::FAILURE;
        }

        $counts = [];
        $syncStatuses = [];

        // Cross-tenant on purpose, like the stale-interview sweep: this is a platform operation.
        $templates = AvatarTemplate::withoutGlobalScopes()
            ->whereNotNull('organization_id')
            ->whereNull('deleted_at')
            ->whereIn('provider', self::PROVIDERS)
            ->orderBy('id')
            ->get();

        foreach ($templates as $template) {
            $label = sprintf('template=%d org=%d provider=%s name="%s"', $template->id, $template->organization_id, $template->provider, $this->configName($template));

            $syncStatus = null;
            $plan = HrAvatarTemplateMapping::map($template->provider, $template->config ?? []);
            $dropped = '';

            if ($template->hr_avatar_config_id !== null) {
                $outcome = 'already_linked';
            } elseif ($plan['voice_missing']) {
                $outcome = 'failed voice_missing';
            } elseif (! $apply) {
                $outcome = 'would_create';
            } else {
                [$outcome, $syncStatus] = $this->create($template, $plan);
            }

            if ($plan['dropped'] !== [] && ($outcome === 'would_create' || str_starts_with($outcome, 'created'))) {
                $dropped = ' dropped_key='.implode(',', $plan['dropped']);
                $counts['dropped_key'] = ($counts['dropped_key'] ?? 0) + 1;
            }

            $kind = explode(' ', $outcome, 2)[0];
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;

            if ($syncStatus !== null) {
                $syncStatuses[$syncStatus] = ($syncStatuses[$syncStatus] ?? 0) + 1;
            }

            $this->line("{$label} {$outcome}{$dropped}");

            if ($outcome === 'failed provider_unreachable_ambiguous') {
                $this->line(sprintf(
                    '  The request may have created the config upstream. Before re-running --apply, list HR-Avatar configs (GET /v1/avatar-configs?external_ref=%s) and link or delete the one named "%s": a blind retry can create a duplicate.',
                    HrAvatarConnection::organizationRef((int) $template->organization_id),
                    $this->configName($template),
                ));
            }
        }

        foreach ($counts as $outcome => $count) {
            $this->line("{$outcome}: {$count}");
        }

        if ($syncStatuses !== []) {
            $this->line('sync_status: '.implode(' ', array_map(fn (string $status, int $count): string => "{$status}={$count}", array_keys($syncStatuses), $syncStatuses)));
            $this->line('A config that is not synced (pending) cannot start a conversation until it is. Do not switch a project to an hr_avatar template whose config is not synced yet: re-check it in the template picker.');
        }

        return isset($counts['failed']) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{config: array<string, mixed>, persona: array<string, mixed>, voice: array{engine: string, voice_id: string}|null, dropped: list<string>, voice_missing: bool}  $plan
     * @return array{0: string, 1: string|null} the outcome line and, for a created config, its sync status
     */
    private function create(AvatarTemplate $template, array $plan): array
    {
        try {
            $response = HrAvatarConnection::client()->post('/v1/avatar-configs', $this->body($template, $plan));
        } catch (ConnectionException) {
            return ['failed provider_unreachable_ambiguous', null];
        }

        if (! $response->successful()) {
            return ['failed '.$this->failureCode($response->status()), null];
        }

        $id = $response->json('id');

        if (! HrAvatarConnection::isConfigId($id)) {
            return ['failed provider_bad_response', null];
        }

        // The query builder, not the model: no `updated_at`, no events, and the predicate means a concurrent
        // second run (or an operator's edit) is never overwritten.
        // The config now exists upstream: whatever goes wrong here leaves it with nothing linking it, so its id is
        // printed (opaque, not a secret) for an operator to link or delete, and the run goes on. The exception text
        // can carry SQL and bindings, so it is never printed.
        try {
            $written = DB::table('avatar_templates')
                ->where('id', $template->id)
                ->where('organization_id', $template->organization_id)
                ->whereNull('hr_avatar_config_id')
                ->update(['hr_avatar_config_id' => $id]);
        } catch (Throwable) {
            return ["failed link_write_failed orphaned_config={$id}", null];
        }

        if ($written === 0) {
            return ["failed write_conflict orphaned_config={$id}", null];
        }

        // Upstream text is never echoed: only a short lowercase token is.
        $status = $response->json('sync_status');
        $status = is_string($status) && preg_match('/^[a-z_]{1,32}$/', $status) === 1 ? $status : 'unknown';

        return ["created {$id} sync_status={$status}", $status];
    }

    /**
     * @param  array{config: array<string, mixed>, persona: array<string, mixed>, voice: array{engine: string, voice_id: string}|null, dropped: list<string>, voice_missing: bool}  $plan
     * @return array{name: string, provider: string, config: array<string, mixed>, external_ref: string, persona?: array<string, mixed>, voice?: array{engine: string, voice_id: string}}
     */
    private function body(AvatarTemplate $template, array $plan): array
    {
        $body = [
            'name' => $this->configName($template),
            'provider' => $template->provider,
            'config' => $plan['config'],
            'external_ref' => HrAvatarConnection::organizationRef((int) $template->organization_id),
        ];

        if ($plan['persona'] !== []) {
            $body['persona'] = $plan['persona'];
        }

        if ($plan['voice'] !== null) {
            $body['voice'] = $plan['voice'];
        }

        return $body;
    }

    private function configName(AvatarTemplate $template): string
    {
        return mb_substr($template->name, 0, 120);
    }

    private function failureCode(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'provider_unauthorized',
            $status === 429 => 'provider_rate_limited',
            $status >= 500 => 'provider_unavailable',
            default => 'provider_rejected',
        };
    }
}
