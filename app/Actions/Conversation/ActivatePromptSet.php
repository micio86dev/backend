<?php

declare(strict_types=1);

namespace App\Actions\Conversation;

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
 * nothing. Activation only affects the NEXT composition: a session already
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
    public function handle(int|string $ref): ConversationPromptSet
    {
        $set = DB::transaction(function () use ($ref): ConversationPromptSet {
            $set = ConversationPromptSet::query()
                ->where(is_int($ref) ? 'id' : 'label', $ref)
                ->lockForUpdate()
                ->first() ?? throw PromptSetException::unknownSet($ref);

            $this->resolver->verify($set);

            if ($set->is_active) {
                return $set;
            }

            ConversationPromptSet::query()->where('is_active', true)->update(['is_active' => false]);
            $set->forceFill(['is_active' => true, 'activated_at' => now()])->save();

            return $set;
        });

        DB::afterCommit(PromptSetResolver::flushCache(...));

        return $set;
    }
}
