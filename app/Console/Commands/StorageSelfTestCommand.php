<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Live round-trip probe against the CONFIGURED default disk (object-storage-fix, D4).
 *
 * The one thing a faked-disk suite can never prove: that the driver, the
 * endpoint, path-style addressing and the bucket jurisdiction (R2's `.eu.`
 * host) actually agree with each other over the network. Defect 1
 * (proposal.md) — the missing Flysystem adapter — made every prior "green"
 * snapshot test meaningless, because none of them ever left the process.
 *
 * Deliberately NOT a Pest test in the default suite: it needs real network
 * access and real credentials, neither of which belong in `php artisan test
 * --parallel` or CI. It is meant to be run where the credentials actually
 * live — `railway run --service api php artisan beai:storage-selftest` — and
 * is wrapped for local/CI use by the credential-gated
 * tests/Integration/Storage/LiveDiskRoundTripTest.php (D4.2), which skips
 * cleanly when AWS_ACCESS_KEY_ID is absent.
 *
 * Steps, in order, each one a distinct failure to report:
 *   1. write   — Storage::put() under a throwaway key, never collides with
 *                real snapshot keys (candidate data lives under
 *                {org}/{participant}/{session}/{uuid}.jpg; this lives under
 *                _selftest/, which is not a valid org id).
 *   2. exists  — the write is visible through the SAME disk resolution the
 *                application uses (no argument — D1).
 *   3. presign — temporaryUrl(15m), the exact call SessionReviewController
 *                makes for backoffice review.
 *   4. fetch   — a REAL HTTP GET of the presigned URL, byte-compared against
 *                what was written. This is the step a faked disk cannot
 *                exercise at all: it proves the endpoint, the signature and
 *                the network path all agree.
 *   5. delete  — the exact call PurgeExpiredDataCommand makes.
 *   6. missing — confirms the delete actually reached the backend.
 *
 * Cleans up on every exit path (including failure) so a broken run never
 * leaves a stray object in the bucket.
 */
final class StorageSelfTestCommand extends Command
{
    protected $signature = 'beai:storage-selftest';

    protected $description = 'Write, presign, fetch and delete a throwaway object on the configured default disk — proves the real backend, not a fake';

    public function handle(): int
    {
        $key = '_selftest/'.(string) Str::uuid().'.jpg';
        $payload = random_bytes(256);
        $disk = Storage::disk();

        try {
            // Deliberately does not print which disk is under test: doing so
            // would mean reading the config key directly, which is exactly
            // the second resolution point D1/D2 forbid under app/ (enforced
            // by tests/Arch/Storage/SingleStorageDiskArchTest.php). The disk
            // itself was already resolved, argument-less, above.
            $this->line("Key under test: [{$key}]");

            $disk->put($key, $payload);
            $this->info('write: OK');

            if (! $disk->exists($key)) {
                $this->error('exists: FAILED — object not visible immediately after write.');

                return self::FAILURE;
            }
            $this->info('exists: OK');

            $url = $disk->temporaryUrl($key, now()->addMinutes(15));
            $this->info("temporaryUrl: OK ({$url})");

            $response = Http::timeout(15)->get($url);
            if (! $response->successful()) {
                $this->error("fetch: FAILED — HTTP {$response->status()} from the presigned URL.");

                return self::FAILURE;
            }

            if ($response->body() !== $payload) {
                $this->error('fetch: FAILED — fetched bytes do not match what was written.');

                return self::FAILURE;
            }
            $this->info('fetch: OK (byte-for-byte match)');

            $disk->delete($key);
            $this->info('delete: OK');

            // missing(), not !exists(): the semantic inverse the contract
            // already provides for exactly this check, and a distinct call
            // from the exists() probe above — the object's actual state on
            // the backend changed via delete() in between, so this MUST be a
            // real, independent I/O round-trip, never a memoized answer.
            if (! $disk->missing($key)) {
                $this->error('missing: FAILED — object still present after delete.');

                return self::FAILURE;
            }
            $this->info('missing: OK');

            $this->info('beai:storage-selftest PASSED — the configured disk is real, reachable and round-trips correctly.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("storage-selftest FAILED: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            // Best-effort cleanup on every exit path — a failed probe must
            // never leave a stray object in the bucket for the next run to
            // trip over, or worse, for someone to mistake for real data.
            try {
                if ($disk->exists($key)) {
                    $disk->delete($key);
                }
            } catch (Throwable) {
                // Already reported above if this was the failing step;
                // nothing further to do here.
            }
        }
    }
}
