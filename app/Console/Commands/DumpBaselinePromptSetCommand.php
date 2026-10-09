<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PromptFragmentKey;
use App\Support\Conversation\BaselinePromptFragments;
use Illuminate\Console\Command;

/**
 * `php artisan beai:prompt-set:dump-baseline [--label=baseline-1] [--directory=]`
 * (db-driven-conversation-prompts PR7, design N-10).
 *
 * Writes the baseline prompt set to `database/prompt-sets/<label>.json` FROM
 * {@see BaselinePromptFragments}, in the format `beai:prompt-set:publish` reads.
 * Every `PromptFragmentKey` is written for `en` and `it`; the `it` rows are
 * verbatim copies of `en` because the composer speaks English instructions for
 * every locale.
 *
 * The file is a FROZEN artefact: the bootstrap data migration stores exactly
 * these bytes under this label, and a stored set is immutable. So the output is
 * deterministic (keys sorted byte-wise, fixed JSON flags, one trailing newline)
 * and the command never overwrites a file whose content differs. When a
 * baseline literal changes, dump it under a NEW label (`baseline-2`, with its
 * own migration or a publish plus activate); `baseline-1` is never regenerated.
 *
 * `--directory` exists for tests and for dumping somewhere other than the repository.
 */
final class DumpBaselinePromptSetCommand extends Command
{
    /** The first baseline snapshot; N counts snapshots, bumped whenever the baseline text changes. */
    public const DEFAULT_LABEL = 'baseline-1';

    private const NOTES = 'The conversation prompt baseline, dumped from BaselinePromptFragments. '
        .'The it rows are verbatim copies of the en rows: the composer speaks English instructions for every locale.';

    protected $signature = 'beai:prompt-set:dump-baseline
        {--label='.self::DEFAULT_LABEL.' : Label of the set; also the file name (1 to 64 of a-z, 0-9, dot, underscore, hyphen)}
        {--directory= : Directory to write into (default: database/prompt-sets)}';

    protected $description = 'Dump the code baseline of the conversation prompts to a versioned JSON file';

    public function handle(): int
    {
        $label = (string) $this->option('label');

        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $label) !== 1) {
            $this->error('The label must be 1 to 64 characters of a-z, 0-9, dot, underscore and hyphen, starting with a letter or digit.');

            return self::FAILURE;
        }

        $directory = is_string($this->option('directory')) && $this->option('directory') !== ''
            ? rtrim((string) $this->option('directory'), '/')
            : database_path('prompt-sets');
        $path = "{$directory}/{$label}.json";
        $json = $this->render($label);

        if (is_file($path)) {
            if (file_get_contents($path) !== $json) {
                $this->error("The file [{$path}] already exists with different content; refusing to overwrite it. A stored prompt set is immutable: dump a changed baseline under a new --label.");

                return self::FAILURE;
            }

            $this->info("The file [{$path}] is already up to date; nothing written.");

            return self::SUCCESS;
        }

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error("The directory [{$directory}] cannot be created.");

            return self::FAILURE;
        }

        if (file_put_contents($path, $json, LOCK_EX) === false) {
            $this->error("The file [{$path}] cannot be written.");

            return self::FAILURE;
        }

        $this->info("Wrote prompt set [{$label}] to {$path} (".count(PromptFragmentKey::cases()).' fragments x 2 locales, '.strlen($json).' bytes).');

        return self::SUCCESS;
    }

    /**
     * The exact bytes of the file: the publish format, keys sorted byte-wise.
     */
    private function render(string $label): string
    {
        $fragments = [];

        foreach (['en', 'it'] as $locale) {
            $bodies = BaselinePromptFragments::forLocale($locale);
            uksort($bodies, static fn (int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));
            $fragments[$locale] = $bodies;
        }

        return json_encode(
            ['label' => $label, 'notes' => self::NOTES, 'fragments' => $fragments, 'overrides' => []],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }
}
