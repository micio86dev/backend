<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Conversation\ActivatePromptSet;
use App\Exceptions\Conversation\PromptSetException;
use App\Exceptions\Conversation\PromptTemplateUnresolvableException;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan beai:prompt-set:activate {label}` (db-driven-conversation-prompts PR6b).
 *
 * Makes a published prompt set the active one. The set is verified first (seal,
 * key set, placeholder contract); an unverifiable set is refused and the
 * incumbent stays active. Re-activating the active set is a no-op. Only the
 * NEXT interview composition is affected.
 */
final class ActivatePromptSetCommand extends Command
{
    protected $signature = 'beai:prompt-set:activate {label : Label of the published set}';

    protected $description = 'Activate a published conversation prompt set';

    public function handle(ActivatePromptSet $activate): int
    {
        $label = (string) $this->argument('label');

        try {
            $activation = $activate->handle($label);
        } catch (PromptSetException|PromptTemplateUnresolvableException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Activation failed ('.$e::class.'); the active set is unchanged.');

            return self::FAILURE;
        }

        $set = $activation->set;

        $this->info($activation->changed
            ? "Activated prompt set [{$set->label}] (id {$set->id}). New interviews compose from it."
            : "Prompt set [{$set->label}] is already active; nothing changed.");

        return self::SUCCESS;
    }
}
