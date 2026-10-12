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
        $seconds = (int) $this->argument('seconds');
        $ceiling = ProviderFieldSpecs::TAVUS_MAX_SECONDS;
        if ($seconds < 30 || $seconds > $ceiling) {
            $this->error("seconds must be between 30 and {$ceiling} (the Tavus ceiling), got {$seconds}.");

            return self::FAILURE;
        }

        $wasBypass = $this->tenant->isBypass();
        $this->tenant->setBypass(true);
        try {
            $template = AvatarTemplate::query()->find((int) $this->argument('template'));
        } finally {
            $this->tenant->setBypass($wasBypass);
        }

        if ($template === null) {
            $this->error('No avatar template with that id.');

            return self::FAILURE;
        }
        if ($template->provider !== 'tavus') {
            $this->error("Template {$template->id} is provider '{$template->provider}', not 'tavus'. Nothing changed.");

            return self::FAILURE;
        }

        if ($template->organization_id === null) {
            $this->error('This is a platform template: write it through the superadmin, not this command. Nothing changed.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = TenantContextScope::runFor(
            $template->organization_id,
            fn (): ?array => $this->writeMaxCallDuration($template->id, $seconds, $dryRun),
        );

        if ($result === null) {
            $this->error('The template disappeared before the change. Nothing changed.');

            return self::FAILURE;
        }

        $this->line("Template {$template->id}: maxCallDurationSec {$this->display($result['before'])} -> {$seconds}");

        if ($dryRun) {
            $this->info('Dry run: nothing saved.');

            return self::SUCCESS;
        }

        if (! $result['saved']) {
            $this->error('The template could not be saved. Nothing changed.');

            return self::FAILURE;
        }

        $this->info("Saved: template {$template->id} now allows {$seconds} seconds.");

        return self::SUCCESS;
    }

    /**
     * Reads the template under a row lock and writes only its maxCallDurationSec key, so a
     * concurrent edit to the other config keys cannot be overwritten by a stale copy.
     *
     * @return array{before: mixed, saved: bool}|null
     */
    private function writeMaxCallDuration(int $templateId, int $seconds, bool $dryRun): ?array
    {
        return DB::transaction(function () use ($templateId, $seconds, $dryRun): ?array {
            $fresh = AvatarTemplate::query()->lockForUpdate()->find($templateId);
            if ($fresh === null) {
                return null;
            }

            $config = $fresh->config;
            $before = $config['maxCallDurationSec'] ?? null;
            if ($dryRun) {
                return ['before' => $before, 'saved' => false];
            }

            $config['maxCallDurationSec'] = $seconds;
            $fresh->config = $config;

            return ['before' => $before, 'saved' => (bool) $fresh->save()];
        });
    }

    private function display(mixed $value): string
    {
        return $value === null ? '(not set)' : (string) $value;
    }
}
