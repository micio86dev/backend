<?php

declare(strict_types=1);

/**
 * Credential-gated wrapper for `beai:storage-selftest` (object-storage-fix, D4.2).
 *
 * NOT registered in any phpunit.xml <testsuite> (Unit/Feature/Arch only) — this
 * directory is deliberately outside `php artisan test --parallel` and therefore
 * outside CI and every local contributor's default run. It exists to let this
 * exact assertion be driven from wherever live credentials actually are (e.g.
 * `railway run --service api ./vendor/bin/pest tests/Integration`), without
 * ever risking a real network call inside the default suite.
 *
 * Skips cleanly — not a failure — when live storage credentials are absent,
 * which is every environment except one actually configured for the `s3`
 * disk. Gated on ALL FOUR required `s3`-disk variables (D6:
 * AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_BUCKET, AWS_ENDPOINT), not on
 * AWS_ACCESS_KEY_ID alone: an access-key-only gate is a false negative on any
 * machine that happens to export an unrelated AWS_ACCESS_KEY_ID (e.g. a
 * developer's own AWS CLI / another S3-compatible provider's credentials) —
 * observed empirically in the apply session for this change, where the shell
 * carried a DigitalOcean Spaces key pair under the same variable names. That
 * pair alone would have let this test attempt a REAL, wrong network call
 * instead of skipping. Requiring the bucket + endpoint too means only a shell
 * actually configured for THIS disk activates the probe.
 *
 * ALSO gated on `FILESYSTEM_DISK === 's3'` (post-apply verification finding,
 * WARNING 1): the four AWS_* vars can all be genuinely present while
 * FILESYSTEM_DISK is still `local` (e.g. a dev machine with real R2
 * credentials exported for some other tool, but developing locally as
 * intended). Without this check the command below would round-trip against
 * the LOCAL disk and still print "the configured disk is real, reachable and
 * round-trips correctly" — a FALSE GREEN that proves nothing about `s3`,
 * which is the entire point of this probe (D4).
 */
test('beai:storage-selftest passes against the configured live disk', function (): void {
    $this->artisan('beai:storage-selftest')->assertSuccessful();
})->group('storage-live')
    ->skip(
        fn (): bool => blank(env('AWS_ACCESS_KEY_ID'))
            || blank(env('AWS_SECRET_ACCESS_KEY'))
            || blank(env('AWS_BUCKET'))
            || blank(env('AWS_ENDPOINT'))
            || env('FILESYSTEM_DISK') !== 's3',
        'live storage credentials absent, or FILESYSTEM_DISK is not s3'
    );
