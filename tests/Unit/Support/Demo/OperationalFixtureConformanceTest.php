<?php

declare(strict_types=1);

/**
 * Pure, no-DB conformance checks on the four new operational-surface fixture
 * blocks in `DemoDataset` (design D14/D15/D17/D18), run BEFORE any write.
 *
 * Scope, stated honestly: only the CHECK-constraint halves that are fully
 * derivable from the fixture itself are asserted here. `delivered_at` and
 * `sent_at` are computed by `DemoWriter` at write time (persisted-anchor +
 * offset, design D16) — not authored in the fixture — so their equivalences
 * (`status='delivered' <=> delivered_at IS NOT NULL`,
 * `status='sent' <=> sent_at IS NOT NULL`) are enforced by the raw-DDL CHECK
 * constraints already migrated, and re-verified against a real database by
 * `Feature\Demo\DashboardMetricsPopulatedTest` and
 * `Feature\Demo\OperationalTeardownCompletenessTest` (a writer bug there
 * throws a DB constraint violation, not a silent bad row).
 */

use App\Support\Demo\DemoDataset;

test('every authored ai_requests row satisfies (success=false) = (failure_reason IS NOT NULL)', function (): void {
    foreach (DemoDataset::aiRequestCalls() as $participantKey => $rows) {
        foreach ($rows as $index => $row) {
            $hasFailureReason = $row['failure_reason'] !== null;

            expect(! $row['success'])
                ->toBe($hasFailureReason, "ai_requests[{$participantKey}][{$index}]: success/failure_reason equivalence violated.");
        }
    }
});

test('at least one ai_requests row is success=false and the rest are success=true with null failure_reason', function (): void {
    $flat = collect(DemoDataset::aiRequestCalls())->flatten(1);

    expect($flat->where('success', false)->count())->toBe(1);
    expect($flat->where('success', true)->every(fn (array $row): bool => $row['failure_reason'] === null))->toBeTrue();
});

test('the ai_requests fixture has exactly 26 rows', function (): void {
    $flat = collect(DemoDataset::aiRequestCalls())->flatten(1);

    expect($flat->count())->toBe(26);
});

test('every authored ai_requests model key exists in the cost rate table', function (): void {
    $rates = config('scoring.cost_rates_usd_per_million', []);

    foreach (DemoDataset::aiRequestCalls() as $participantKey => $rows) {
        foreach ($rows as $index => $row) {
            expect(array_key_exists($row['model'], $rates))
                ->toBeTrue("ai_requests[{$participantKey}][{$index}]: model [{$row['model']}] is not in the rate table — would be a silent 0.000000 anomaly row.");
        }
    }
});

test('the webhook_deliveries fixture has 8 rows with a complete status spread and satisfies the fixture-derivable CHECKs', function (): void {
    $rows = DemoDataset::webhookDeliveries();

    expect($rows)->toHaveCount(8);

    $statuses = collect($rows)->pluck('status');
    foreach (['delivered', 'dead', 'pending', 'failed_permanent', 'skipped'] as $expected) {
        expect($statuses)->toContain($expected);
    }

    $maxAttempts = (int) config('webhooks.delivery.max_attempts');

    foreach ($rows as $row) {
        $isSkipped = $row['status'] === 'skipped';
        $hasSkipReason = array_key_exists('skip_reason', $row) && $row['skip_reason'] !== null;

        expect($isSkipped)->toBe($hasSkipReason, "webhook_deliveries[{$row['key']}]: skipped <=> skip_reason violated.");

        if ($isSkipped) {
            expect($row['attempt_count'] ?? 0)->toBe(0, "webhook_deliveries[{$row['key']}]: skipped rows must have attempt_count=0.");
        }

        expect($row['attempt_count'] ?? 0)->toBeLessThanOrEqual($maxAttempts, "webhook_deliveries[{$row['key']}]: attempt_count exceeds max_attempts.");
    }
});

test('every webhook_deliveries row has a unique key and a unique dedupe identity', function (): void {
    $rows = DemoDataset::webhookDeliveries();

    $keys = collect($rows)->pluck('key');
    expect($keys->unique()->count())->toBe($keys->count());

    $dedupeIdentities = collect($rows)->map(
        fn (array $row): string => implode('|', [
            $row['participant_key'],
            $row['event_type'],
            $row['progress_kind'] ?? 'n/a',
            $row['competency_code'] ?? 'n/a',
        ])
    );
    expect($dedupeIdentities->unique()->count())->toBe($dedupeIdentities->count());
});

test('the api_clients fixture has exactly 3 rows, one per state, each beai-demo- marked', function (): void {
    $rows = DemoDataset::apiClients();

    expect($rows)->toHaveCount(3);
    expect(collect($rows)->pluck('key')->sort()->values()->all())->toBe(['active', 'expired', 'revoked']);

    foreach ($rows as $row) {
        expect($row['name'])->toStartWith('beai-demo-');
    }
});

test('the notification_logs fixture has 3 rows spanning both types and sent/suppressed/failed, satisfying the fixture-derivable CHECK', function (): void {
    $rows = DemoDataset::notificationLogs();

    expect($rows)->toHaveCount(3);

    $types = collect($rows)->pluck('notification_type')->unique();
    expect($types)->toContain('webhook_delivery_dead');
    expect($types)->toContain('scoring_failed');

    $statuses = collect($rows)->pluck('status');
    foreach (['sent', 'suppressed', 'failed'] as $expected) {
        expect($statuses)->toContain($expected);
    }

    foreach ($rows as $row) {
        $isSuppressed = $row['status'] === 'suppressed';
        $hasSuppressionReason = array_key_exists('suppression_reason', $row) && $row['suppression_reason'] !== null;

        expect($isSuppressed)->toBe($hasSuppressionReason, 'notification_logs row satisfies (status=suppressed) = (suppression_reason IS NOT NULL).');
    }
});

test('no notification_logs subject is repeated across rows', function (): void {
    $rows = DemoDataset::notificationLogs();

    $subjects = collect($rows)->map(fn (array $row): string => $row['subject_kind'].':'.$row['subject_key']);

    expect($subjects->unique()->count())->toBe($subjects->count());
});

test('webhookConfig defines P1/P2/P4 and leaves P3 unconfigured, matching D13', function (): void {
    $config = DemoDataset::webhookConfig();

    expect($config)->toHaveKeys(['P1', 'P2', 'P4']);
    expect($config)->not->toHaveKey('P3');

    expect($config['P1']['has_secret'])->toBeTrue();
    expect($config['P1']['webhook_events'])->toBe(['progress', 'evaluation']);

    expect($config['P2']['has_secret'])->toBeTrue();
    expect($config['P2']['webhook_events'])->toBe(['evaluation']);

    expect($config['P4']['has_secret'])->toBeFalse();
    expect($config['P4']['webhook_events'])->toBe(['evaluation']);

    foreach ($config as $definition) {
        expect($definition['webhook_url'])->toContain('webhooks.invalid');
    }
});
