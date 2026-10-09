<?php

declare(strict_types=1);

namespace App\Actions\Conversation;

use App\DTOs\Conversation\PromptSetActivation;
use App\Exceptions\Conversation\PromptSetException;
use App\Exceptions\Conversation\PromptTemplateUnresolvableException;
use App\Models\ConversationPromptSet;
use App\Services\Conversation\PromptSetResolver;
use Illuminate\Support\Facades\DB;

/**
 * Makes one stored prompt set the active one
 * (db-driven-conversation-prompts PR6b, design N-4/N-9).
 *
 * In ONE transaction: the target is verified by the same checks a composition
 * runs ({@see PromptSetResolver::verify()}), so a damaged or tampered set can
 * never become active; the incumbent is deactivated FIRST, because the
 * one-active partial unique index is not deferrable; then the target is
 * activated and `activated_at` recorded. Re-activating the active set changes
 * nothing and the result says so ({@see PromptSetActivation::$changed}). Activation only affects the NEXT composition: a session already
 * composed keeps the set it was stamped with.
 */
final class ActivatePromptSet
{
    public function __construct(private readonly PromptSetResolver $resolver) {}

    /**
     * @param  int|string  $ref  A set id, or its label.
     *
     * @throws PromptSetException When no set matches.
     * @throws PromptTemplateUnresolvableException When the set fails verification.
     */
    public function handle(int|string $ref): PromptSetActivation
    {
        $activation = DB::transaction(function () use ($ref): PromptSetActivation {
            $set = ConversationPromptSet::query()
                ->where(is_int($ref) ? 'id' : 'label', $ref)
                ->lockForUpdate()
                ->first() ?? throw PromptSetException::unknownSet($ref);

            $this->resolver->verify($set);

            if ($set->is_active) {
                return new PromptSetActivation($set, false);
            }

            ConversationPromptSet::query()->where('is_active', true)->update(['is_active' => false]);
            $set->forceFill(['is_active' => true, 'activated_at' => now()])->save();

            return new PromptSetActivation($set, true);
        });

        DB::afterCommit(PromptSetResolver::flushCache(...));

        return $activation;
    }
}
