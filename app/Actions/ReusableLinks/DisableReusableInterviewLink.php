<?php

declare(strict_types=1);

namespace App\Actions\ReusableLinks;

use App\Models\ReusableInterviewLink;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * DisableReusableInterviewLink (reusable-interview-links, design AD-12).
 *
 * The only revocation verb: soft, immediate, irreversible and idempotent.
 *
 * The link row is locked for the duration of the write (`FOR UPDATE`), the same
 * lock a redemption takes, so a disable and a redemption of one link are
 * serialised: a visitor is created before the disable commits or the redemption
 * is refused afterwards, never after. The state is re-read UNDER the lock, so two
 * concurrent disables cannot both see an active link and write twice.
 *
 * An already-disabled link is not a mutation: the first `disabled_at` and
 * `disabled_by` stay, nothing is written and nothing is audited, so a repeated
 * click or a retried request leaves exactly one audit row.
 *
 * The audit row is written AFTER the transaction commits, so the trail never
 * claims a disable that did not commit, and a failing audit write (contained by
 * `AuditRecorder`) can never abort the transaction that holds the user's change.
 */
final class DisableReusableInterviewLink
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return bool true when this call disabled the link, false when it already was
     */
    public function handle(ReusableInterviewLink $link, User $actor): bool
    {
        $disabledAt = DB::transaction(function () use ($link, $actor) {
            // Re-selected under the lock and through the tenant scope, so the
            // decision is made on the row as it is now, not as it was read.
            $locked = ReusableInterviewLink::query()
                ->whereKey($link->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->disabled_at !== null) {
                return null;
            }

            $locked->forceFill([
                'disabled_at' => now(),
                'disabled_by' => $actor->getKey(),
            ])->save();

            return $locked->disabled_at;
        });

        if ($disabledAt === null) {
            return false;
        }

        $this->audit->record(
            action: 'reusable_link.disabled',
            subjectType: 'reusable_interview_link',
            subjectId: (int) $link->getKey(),
            before: ['disabled_at' => null],
            after: ['disabled_at' => $disabledAt->toIso8601String()],
        );

        return true;
    }
}
