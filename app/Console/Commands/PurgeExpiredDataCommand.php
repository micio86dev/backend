<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InterviewSnapshot;
use App\Models\Participant;
use App\Models\Utterance;
use App\Models\WebhookDelivery;
use App\Support\Audit\AuditRecorder;
use App\Support\Participant\PlaceholderEmail;
use App\Support\Retention\RetentionPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * GDPR retention purge (C13).
 *
 * SHIPS DISABLED. `config/retention.php` defaults `enabled` to false and every
 * duration to null, and it stays that way until open decision #2 has legal
 * sign-off. Deletion is the one operation with no undo, so the safe state for a
 * finished mechanism to wait in is inert — not "enabled with placeholder
 * numbers", which is a data-loss incident dressed as a default.
 *
 * Two of the four classes are REDACTIONS rather than deletions, and the
 * distinction is the point:
 *
 * - `webhook_deliveries.payload` is REPLACED, the row kept. Whether a customer's
 *   endpoint was told, and when, is an integration audit record that must
 *   outlive the purge of what was said.
 * - `participants.display_name` AND `participants.email` are REPLACED, in the
 *   same pass and under the same window, the row kept: the name with a
 *   sentinel, the email with a non-identifying placeholder derived from the
 *   participant's OWN `candidate_ref` (see `PlaceholderEmail::forPurged()`).
 *   `candidate_ref` is the calling system's own opaque identifier and carries
 *   no personal data; deleting the row would destroy the audit trail without
 *   protecting anybody. The ruling-2 legal sign-off must name the email too.
 *
 * `participants.external_id` and `participants.source` belong to NO class: every
 * class leaves both columns exactly as they are (values retained, NULL left
 * NULL), treated like `candidate_ref`. They are the calling system's own record
 * id and name. This is a DOCUMENTED DEFAULT, not a legal conclusion — an
 * external id can still be linkable personal data in a given integration, so
 * the ruling-2 legal sign-off must also name both columns, and a decision to
 * purge them is one additive class.
 *
 * They are overwritten with a SENTINEL or a PLACEHOLDER rather than nulled, and
 * that is not a workaround. The columns are NOT NULL (and the email is unique per
 * project), and relaxing them would weaken invariants live code depends on —
 * C6's SSO exchange asserts a non-empty display_name, and C10 treats a delivery's
 * payload as always present. The personal data is equally gone either way, so
 * there is no GDPR argument for paying that price. A sentinel is also legible in
 * a UI: "[purged]" reads as a deliberate act, where an empty name reads as a
 * bug.
 *
 * Idempotent by construction: every query filters on the thing the purge
 * removes, so a second run finds nothing.
 */
final class PurgeExpiredDataCommand extends Command
{
    protected $signature = 'beai:purge-expired-data {--dry-run : Report what would be purged without deleting anything}';

    protected $description = 'Delete candidate artifacts past their GDPR retention window (disabled until decision #2 is ratified)';

    /** Sentinel written over redacted personal data. Legible, and NOT NULL-safe. */
    public const PURGED_NAME = '[purged]';

    public const PURGED_PAYLOAD = '{"purged": true}';

    public function handle(RetentionPolicy $policy, AuditRecorder $audit): int
    {
        if (! $policy->isEnabled()) {
            // Reported, never silent. An operator who runs this deserves to be
            // told why nothing happened rather than left assuming it worked.
            $this->warn('Retention is DISABLED (config/retention.php). Nothing was purged.');
            $this->line('This is the intended state until open product decision #2 has legal sign-off.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        foreach ($policy->unratifiedClasses() as $class) {
            $this->warn("Skipping [{$class}]: no ratified retention duration.");
        }

        $total = 0;

        foreach (RetentionPolicy::CLASSES as $class) {
            $cutoff = $policy->cutoffFor($class);

            if ($cutoff === null) {
                continue;
            }

            $count = match ($class) {
                'snapshot' => $this->purgeSnapshots($cutoff, $policy->batchSize(), $dryRun),
                'transcript' => $this->purgeTranscripts($cutoff, $policy->batchSize(), $dryRun),
                'webhook_payload' => $this->redactWebhookPayloads($cutoff, $policy->batchSize(), $dryRun),
                // No default arm: RetentionPolicy::CLASSES is the closed set
                // this loop iterates, so a default would be unreachable — and
                // an unreachable fallback is how a newly-added artifact class
                // silently purges nothing instead of failing loudly.
                'participant_pii' => $this->redactParticipantPii($cutoff, $policy->batchSize(), $dryRun),
            };

            $total += $count;
            $this->info(sprintf('%s [%s]: %d', $dryRun ? 'Would purge' : 'Purged', $class, $count));

            if ($count > 0 && ! $dryRun) {
                // The trail records THAT a purge happened, its scope and its
                // count — never its subject matter. Recording what was deleted
                // in a table designed to be kept would defeat the deletion.
                $audit->record(
                    action: 'data.purged',
                    subjectType: $class,
                    subjectId: null,
                    after: ['count' => $count, 'cutoff' => $cutoff->toIso8601String()],
                );
            }
        }

        $this->info(sprintf('Total: %d', $total));

        return self::SUCCESS;
    }

    /**
     * Snapshots: the stored object goes with the row.
     *
     * Deleting the row alone would leave the image on disk, unreachable through
     * the application and still entirely present — the worst outcome available,
     * because the data is retained AND nobody can find it to prove it.
     */
    private function purgeSnapshots(Carbon $cutoff, int $batch, bool $dryRun): int
    {
        // `taken_at`, NOT `created_at`: interview_snapshots has no created_at
        // column at all (it tracks only when the frame was captured, and
        // $timestamps is false on the model). Filtering on created_at here
        // threw at runtime — found only because this path got a test.
        $rows = InterviewSnapshot::withoutGlobalScopes()
            ->where('taken_at', '<', $cutoff)
            ->limit($batch)
            ->get();

        if ($dryRun) {
            return $rows->count();
        }

        // No argument: resolves through the SAME storage configuration point
        // as the writer (SnapshotController::store()). A second resolution
        // here — even an explicit env-key read — would be a second place the
        // writer and the purge could diverge (D1; enforced by the arch guard
        // at tests/Arch/Storage — D2).
        $disk = Storage::disk();
        $purged = 0;

        foreach ($rows as $row) {
            // Object first: if the delete fails we still hold the row, and the
            // next run retries. The inverse orphans the object permanently.
            try {
                $disk->delete((string) $row->s3_key);
            } catch (\Throwable $e) {
                $this->warn("Could not delete object for snapshot {$row->getKey()}: {$e->getMessage()}");

                continue;
            }

            $row->delete();
            $purged++;
        }

        return $purged;
    }

    private function purgeTranscripts(Carbon $cutoff, int $batch, bool $dryRun): int
    {
        // `ts`, NOT `created_at`: utterances has no created_at column at all
        // (`$timestamps` is false on the model; `ts` is the utterance's own
        // timestamp). Filtering on created_at threw at runtime whenever the
        // class was enabled — found only because this path got a test, the
        // same mistake `purgeSnapshots()` documents for `taken_at`.
        $query = Utterance::withoutGlobalScopes()->where('ts', '<', $cutoff)->limit($batch);

        if ($dryRun) {
            return $query->count();
        }

        return Utterance::withoutGlobalScopes()
            ->whereIn('id', $query->pluck('id'))
            ->delete();
    }

    private function redactWebhookPayloads(Carbon $cutoff, int $batch, bool $dryRun): int
    {
        // Excluding already-purged rows is what makes this idempotent: a second
        // run finds nothing because the first already replaced them.
        $query = WebhookDelivery::withoutGlobalScopes()
            ->where('created_at', '<', $cutoff)
            ->whereRaw('payload::text <> ?', [self::PURGED_PAYLOAD])
            ->limit($batch);

        if ($dryRun) {
            return $query->count();
        }

        return WebhookDelivery::withoutGlobalScopes()
            ->whereIn('id', $query->pluck('id'))
            ->update(['payload' => ['purged' => true]]);
    }

    /**
     * Redact the name AND the email of every due participant, in the same pass.
     *
     * Pending means due (`created_at` older than the cutoff) and "not yet
     * redacted in full": the name is not the sentinel, OR the email is not the
     * participant's own placeholder. The second branch is what makes the pass a
     * backfill for rows an earlier version redacted by name only, and what lets a
     * legacy anonymous row (exactly `<ref>@invalid.beai.local`, already non-identifying)
     * settle without its email being rewritten. A second pass finds nothing.
     *
     * Each row is written alone, in its own transaction, from ITS OWN
     * `candidate_ref` in SQL: no value of another row, another project or another
     * tenant is ever involved, and the old address is never read. One row at a
     * time because a single set-based statement would abort as a whole on one
     * collision (a placeholder equal to an address another participant of the
     * project literally holds), and leave every other due row unredacted.
     * Such a row is reported by id (never by address) and skipped. The placeholder
     * is derived from the row's own reference, so the same collision repeats on
     * every pass until an operator resolves it: the warning says so. Any other
     * database error propagates, as before.
     */
    private function redactParticipantPii(Carbon $cutoff, int $batch, bool $dryRun): int
    {
        $ids = Participant::withoutGlobalScopes()
            ->where('created_at', '<', $cutoff)
            ->where(function ($query): void {
                $query->where('display_name', '<>', self::PURGED_NAME)
                    ->orWhere(function ($email): void {
                        $email->whereRaw("email <> candidate_ref || '".PlaceholderEmail::DOMAIN."'")
                            ->whereRaw('email <> '.PlaceholderEmail::purgedSqlExpression());
                    });
            })
            ->orderBy('id')
            ->limit($batch)
            ->pluck('id');

        if ($dryRun) {
            return $ids->count();
        }

        $redacted = 0;

        foreach ($ids as $id) {
            try {
                $affected = DB::transaction(fn (): int => Participant::withoutGlobalScopes()->whereKey($id)->update([
                    'display_name' => self::PURGED_NAME,
                    // The row's OWN legacy placeholder (exactly `<ref>@invalid.beai.local`,
                    // the spelling `PlaceholderEmail::isOwn()` accepts) is already
                    // non-identifying and is kept as it is; anything else, in any
                    // other spelling, becomes the purged placeholder of this row's
                    // own reference.
                    'email' => DB::raw(
                        "CASE WHEN email = candidate_ref || '".PlaceholderEmail::DOMAIN."' THEN email ELSE "
                        .PlaceholderEmail::purgedSqlExpression().' END',
                    ),
                ]));
            } catch (QueryException $e) {
                if (! self::isDuplicateEmail($e)) {
                    throw $e;
                }

                $this->warn("participant_pii: participant {$id} skipped, needs operator action: its placeholder collides with another address in the same project, and the collision repeats on every pass.");

                continue;
            }

            // The rows actually written: a participant deleted since the
            // selection updates nothing and is not counted (nor audited).
            $redacted += $affected;
        }

        return $redacted;
    }

    /**
     * Whether a database error is the `(project_id, email)` unique violation and
     * nothing else: the SQLSTATE `unique_violation` AND the index name.
     */
    private static function isDuplicateEmail(QueryException $e): bool
    {
        return $e->getCode() === '23505'
            && str_contains($e->getMessage(), 'participants_project_id_email_unique');
    }
}
