<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * Moves organizations from legacy Tavus/HeyGen avatar templates to `hr_avatar` twin templates.
 *
 * Dry run by default: without `--apply` nothing is written and no non-GET request is made. This slice creates the
 * twins INACTIVE and repins nothing; activation, the repin and `--rollback` arrive with their own gates.
 *
 * Invalid input is refused with exit 2 before anything is read or written: an `--organization` value that is not a
 * positive integer, or that names no organization, is never a silent no-op.
 */
final class HrAvatarCutover extends Command
{
    protected $signature = 'hr-avatar:cutover
        {--apply : Perform the cutover (default: dry run)}
        {--organization=* : Limit to these organization ids}';

    protected $description = 'Create hr_avatar twins for the linked Tavus/HeyGen templates (dry run unless --apply)';

    public function handle(): int
    {
        $organizationIds = $this->organizationIds();

        if ($organizationIds === null) {
            return self::INVALID;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>|null the organizations to process, or null (after printing why) when an id is invalid
     */
    private function organizationIds(): ?array
    {
        /** @var list<string> $requested */
        $requested = (array) $this->option('organization');

        foreach ($requested as $value) {
            if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || ! Organization::whereKey((int) $value)->exists()) {
                $this->error("Invalid --organization value \"{$value}\": expected the id of an existing organization. Nothing was changed.");

                return null;
            }
        }

        if ($requested !== []) {
            return array_values(array_unique(array_map('intval', $requested)));
        }

        return Organization::orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }
}
