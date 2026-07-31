<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InterviewSnapshot;
use App\Models\Participant;
use App\Models\Utterance;
use App\Models\WebhookDelivery;
use App\Support\Audit\AuditRecorder;
use App\Support\Retention\RetentionPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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
 * - `participants.display_name` is REPLACED, the row kept. `candidate_ref` is
 *   the calling system's own opaque identifier and carries no personal data;
 *   deleting the row would destroy the audit trail without protecting anybody.
 *
 * Both are overwritten with a SENTINEL rather than nulled, and that is not a
 * workaround. Both columns are NOT NULL, and relaxing them would weaken
 * invariants live code depends on — C6's SSO exchange asserts a non-empty
 * display_name, and C10 treats a delivery's payload as always present. The
 * personal data is equally gone either way, so there is no GDPR argument for
 * paying that price. A sentinel is also legible in a UI: "[purged]" reads as a
 * deliberate act, where an empty name reads as a bug.
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

        $disk = Storage::disk(config('filesystems.default'));
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
        $query = Utterance::withoutGlobalScopes()->where('created_at', '<', $cutoff)->limit($batch);

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

    private function redactParticipantPii(Carbon $cutoff, int $batch, bool $dryRun): int
    {
        $query = Participant::withoutGlobalScopes()
            ->where('created_at', '<', $cutoff)
            ->where('display_name', '<>', self::PURGED_NAME)
            ->limit($batch);

        if ($dryRun) {
            return $query->count();
        }

        return Participant::withoutGlobalScopes()
            ->whereIn('id', $query->pluck('id'))
            ->update(['display_name' => self::PURGED_NAME]);
    }
}
