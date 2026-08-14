<?php

declare(strict_types=1);

/**
 * RED — 1.1: the `s3` disk driver must be installable (object-storage-fix, D4.1).
 *
 * Laravel's `createS3Driver` constructs `AwsS3V3Adapter` eagerly when the disk is
 * resolved — no network call, no credentials needed to reach the failure. Without
 * `league/flysystem-aws-s3-v3` in `composer.json`, resolving the `s3` disk throws
 * `Class "League\Flysystem\AwsS3V3\PortableVisibilityConverter" not found`. This is
 * the zero-cost sentinel that would have caught defect 1 (proposal.md) before any
 * real upload was attempted: `SnapshotControllerTest` never caught it because it
 * fakes the disk with `Storage::fake('s3')`, which needs no adapter at all.
 *
 * Dummy config only: this test asserts installability, not connectivity.
 */

use Illuminate\Support\Facades\Storage;

test('the s3 disk driver resolves without throwing, given dummy config', function (): void {
    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'key' => 'dummy-key',
        'secret' => 'dummy-secret',
        'region' => 'auto',
        'bucket' => 'dummy-bucket',
        'url' => null,
        'endpoint' => 'https://dummy.example.com',
        'use_path_style_endpoint' => true,
        'throw' => false,
        'report' => false,
    ]);

    // Resolving (not touching the network) is enough: `createS3Driver` builds
    // the AwsS3V3Adapter eagerly, so a missing Flysystem package throws right
    // here, before any HTTP call would even be attempted.
    Storage::disk('s3');
})->throwsNoExceptions();
