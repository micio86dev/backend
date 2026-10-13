<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AvatarTemplate;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sets the total interview time limit of one Tavus avatar template.
 *
 * The candidate countdown follows `maxCallDurationSec` of the template. This is a
 * deliberate, explicit command rather than a migration: a migration would run on
 * every deploy and change production data without a decision. Run it once per
 * template, after checking the value with `--dry-run`.
 */
class SetTavusMaxCallDurationCommand extends Command
{
    protected $signature = 'beai:set-tavus-max-call
        {template : The id of the Tavus avatar template to change}
        {seconds=1200 : The total interview time limit, in seconds}
        {--dry-run : Show what would change without saving}';

    protected $description = 'Set the total interview time limit (maxCallDurationSec) of a Tavus avatar template';

    public function __construct(private readonly TenantResolver $tenant)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $rawSeconds = (string) $this->argument('seconds');
        $seconds = (int) $rawSeconds;
        $ceiling = ProviderFieldSpecs::TAVUS_MAX_SECONDS;
        if (! ctype_digit($rawSeconds) || $seconds < 30 || $seconds > $ceiling) {
            $this->error("seconds must be a whole number between 30 and {$ceiling} (the Tavus ceiling), got '{$rawSeconds}'.");

            return self::FAILURE;
        }

        $wasBypass = $this->tenant->isBypass();
        $this->tenant->setBypass(true);
        try {
            $template = AvatarTemplate::query()->find((int) $this->argument('template'));
        } finally {
            $this->tenant->setBypass($wasBypass);
        }

        // A platform template (organization_id IS NULL) is answered exactly like an unknown id.
        $organizationId = $template?->organization_id;
        if ($template === null || $organizationId === null) {
            $this->error('No avatar template with that id.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = TenantContextScope::runFor(
            $organizationId,
            fn (): array => $this->writeMaxCallDuration($template->id, $seconds, $dryRun),
        );

        if ($result['outcome'] === 'missing') {
            $this->error('The template disappeared before the change. Nothing changed.');

            return self::FAILURE;
        }

        if ($result['outcome'] === 'not_tavus') {
            $this->error("Template {$template->id} is provider '{$result['provider']}', not 'tavus'. Nothing changed.");

            return self::FAILURE;
        }

        $this->line("Template {$template->id}: maxCallDurationSec {$this->display($result['before'])} -> {$seconds}");

        if ($dryRun) {
            $this->info('Dry run: nothing saved.');

            return self::SUCCESS;
        }

        if ($result['outcome'] !== 'saved') {
            $this->error('The template could not be saved. Nothing changed.');

            return self::FAILURE;
        }

        $this->info("Saved: template {$template->id} now allows {$seconds} seconds.");

        return self::SUCCESS;
    }

    /**
     * Re-checks the provider and reads the value under a row lock, then writes only the
     * maxCallDurationSec key. A provider or config change made between the lookup and the
     * write is therefore seen, and cannot be overwritten by a stale copy.
     *
     * @return array{outcome: 'missing'|'not_tavus'|'dry_run'|'saved'|'failed', before: mixed, provider: string}
     */
    private function writeMaxCallDuration(int $templateId, int $seconds, bool $dryRun): array
    {
        return DB::transaction(function () use ($templateId, $seconds, $dryRun): array {
            $fresh = AvatarTemplate::query()->lockForUpdate()->find($templateId);
            if ($fresh === null) {
                return ['outcome' => 'missing', 'before' => null, 'provider' => ''];
            }

            $config = $fresh->config;
            $before = $config['maxCallDurationSec'] ?? null;
            if ($fresh->provider !== 'tavus') {
                return ['outcome' => 'not_tavus', 'before' => $before, 'provider' => $fresh->provider];
            }
            if ($dryRun) {
                return ['outcome' => 'dry_run', 'before' => $before, 'provider' => 'tavus'];
            }

            $config['maxCallDurationSec'] = $seconds;
            $fresh->config = $config;

            return [
                'outcome' => $fresh->save() ? 'saved' : 'failed',
                'before' => $before,
                'provider' => 'tavus',
            ];
        });
    }

    private function display(mixed $value): string
    {
        return $value === null ? '(not set)' : (string) $value;
    }
}
