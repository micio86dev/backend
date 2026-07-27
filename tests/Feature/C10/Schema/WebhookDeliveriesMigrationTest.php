<?php

declare(strict_types=1);

/**
 * RED — 2.1: webhook_deliveries table schema (C10).
 *
 * Asserts all expected columns, indexes, and FKs are present, and that the raw-DDL
 * CHECK constraints reject illegal DB-level states at the INSERT boundary (design.md D1).
 * Refs spec: Delivery decision — webhook_events gate and skipped rows.
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: Project, 2: Participant}
 */
function c10WebhookDeliveryFixtures(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create();
    $participant = Participant::factory()->forProject($project)->create();

    return [$org, $project, $participant];
}

/**
 * @return array<string, mixed>
 */
function c10BaseWebhookDeliveryRow(Organization $org, Project $project, Participant $participant): array
{
    return [
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'participant_id' => $participant->id,
        'delivery_id' => (string) Str::uuid(),
        'event_type' => 'evaluation',
        'dedupe_key' => (string) Str::uuid(),
        'status' => 'pending',
        'skip_reason' => null,
        'target_url' => 'https://example.test/hook',
        'payload' => json_encode(['foo' => 'bar'], JSON_THROW_ON_ERROR),
        'payload_version' => '1.0',
        'attempt_count' => 0,
        'max_attempts' => 6,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

test('webhook_deliveries table exists', function (): void {
    expect(Schema::hasTable('webhook_deliveries'))->toBeTrue();
});

test('webhook_deliveries table has all required columns', function (): void {
    $columns = [
        'id', 'organization_id', 'project_id', 'participant_id', 'delivery_id',
        'event_type', 'dedupe_key', 'status', 'skip_reason', 'target_url',
        'payload', 'payload_version', 'attempt_count', 'max_attempts',
        'last_attempt_at', 'next_attempt_at', 'delivered_at',
        'last_response_status', 'last_error', 'created_at', 'updated_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('webhook_deliveries', $column))->toBeTrue(
            "Expected webhook_deliveries table to have column '{$column}'"
        );
    }
});

test('webhook_deliveries has a unique index on (organization_id, project_id, event_type, dedupe_key)', function (): void {
    $indexes = Schema::getIndexes('webhook_deliveries');

    $dedupeIndex = collect($indexes)->first(function (array $index): bool {
        return $index['unique'] === true
            && count($index['columns']) === 4
            && in_array('organization_id', $index['columns'], true)
            && in_array('project_id', $index['columns'], true)
            && in_array('event_type', $index['columns'], true)
            && in_array('dedupe_key', $index['columns'], true);
    });

    expect($dedupeIndex)->not->toBeNull(
        'Expected a unique index on (organization_id, project_id, event_type, dedupe_key).'
    );
});

test('webhook_deliveries has a unique index on delivery_id', function (): void {
    $indexes = Schema::getIndexes('webhook_deliveries');

    $deliveryIdIndex = collect($indexes)->first(function (array $index): bool {
        return $index['unique'] === true
            && count($index['columns']) === 1
            && $index['columns'][0] === 'delivery_id';
    });

    expect($deliveryIdIndex)->not->toBeNull('Expected a unique index on delivery_id.');
});

test('webhook_deliveries has a composite index on (organization_id, status, next_attempt_at)', function (): void {
    $indexes = Schema::getIndexes('webhook_deliveries');

    $index = collect($indexes)->first(function (array $index): bool {
        return count($index['columns']) === 3
            && in_array('organization_id', $index['columns'], true)
            && in_array('status', $index['columns'], true)
            && in_array('next_attempt_at', $index['columns'], true);
    });

    expect($index)->not->toBeNull(
        'Expected a composite index on (organization_id, status, next_attempt_at).'
    );
});

test('webhook_deliveries has a composite index on (organization_id, participant_id)', function (): void {
    $indexes = Schema::getIndexes('webhook_deliveries');

    $index = collect($indexes)->first(function (array $index): bool {
        return count($index['columns']) === 2
            && in_array('organization_id', $index['columns'], true)
            && in_array('participant_id', $index['columns'], true);
    });

    expect($index)->not->toBeNull('Expected a composite index on (organization_id, participant_id).');
});

test('webhook_deliveries has FK organization_id with cascadeOnDelete', function (): void {
    $constraints = DB::select(
        "SELECT kcu.column_name, rc.delete_rule
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
         JOIN information_schema.referential_constraints rc
           ON tc.constraint_name = rc.constraint_name
         WHERE tc.table_name = 'webhook_deliveries'
           AND tc.constraint_type = 'FOREIGN KEY'
           AND kcu.column_name = 'organization_id'
           AND rc.delete_rule = 'CASCADE'"
    );

    expect($constraints)->not->toBeEmpty('Expected FK on organization_id with CASCADE on delete');
});

test('webhook_deliveries has FK project_id with restrictOnDelete', function (): void {
    $constraints = DB::select(
        "SELECT kcu.column_name, rc.delete_rule
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
         JOIN information_schema.referential_constraints rc
           ON tc.constraint_name = rc.constraint_name
         WHERE tc.table_name = 'webhook_deliveries'
           AND tc.constraint_type = 'FOREIGN KEY'
           AND kcu.column_name = 'project_id'
           AND rc.delete_rule = 'RESTRICT'"
    );

    expect($constraints)->not->toBeEmpty('Expected FK on project_id with RESTRICT on delete');
});

test('webhook_deliveries has FK participant_id with cascadeOnDelete', function (): void {
    $constraints = DB::select(
        "SELECT kcu.column_name, rc.delete_rule
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
         JOIN information_schema.referential_constraints rc
           ON tc.constraint_name = rc.constraint_name
         WHERE tc.table_name = 'webhook_deliveries'
           AND tc.constraint_type = 'FOREIGN KEY'
           AND kcu.column_name = 'participant_id'
           AND rc.delete_rule = 'CASCADE'"
    );

    expect($constraints)->not->toBeEmpty('Expected FK on participant_id with CASCADE on delete');
});

test('a legal pending row insert succeeds', function (): void {
    [$org, $project, $participant] = c10WebhookDeliveryFixtures();

    DB::table('webhook_deliveries')->insert(
        c10BaseWebhookDeliveryRow($org, $project, $participant)
    );

    expect(DB::table('webhook_deliveries')->count())->toBe(1);
});

test('CHECK constraint rejects status=skipped with skip_reason null', function (): void {
    [$org, $project, $participant] = c10WebhookDeliveryFixtures();

    $row = c10BaseWebhookDeliveryRow($org, $project, $participant);
    $row['status'] = 'skipped';
    $row['skip_reason'] = null;
    $row['attempt_count'] = 0;

    expect(fn () => DB::table('webhook_deliveries')->insert($row))
        ->toThrow(QueryException::class);
});

test('CHECK constraint rejects status=pending with skip_reason set', function (): void {
    [$org, $project, $participant] = c10WebhookDeliveryFixtures();

    $row = c10BaseWebhookDeliveryRow($org, $project, $participant);
    $row['status'] = 'pending';
    $row['skip_reason'] = 'no_webhook_url';

    expect(fn () => DB::table('webhook_deliveries')->insert($row))
        ->toThrow(QueryException::class);
});

test('CHECK constraint rejects status=delivered with delivered_at null', function (): void {
    [$org, $project, $participant] = c10WebhookDeliveryFixtures();

    $row = c10BaseWebhookDeliveryRow($org, $project, $participant);
    $row['status'] = 'delivered';
    $row['delivered_at'] = null;

    expect(fn () => DB::table('webhook_deliveries')->insert($row))
        ->toThrow(QueryException::class);
});

test('CHECK constraint rejects status=skipped with attempt_count greater than zero', function (): void {
    [$org, $project, $participant] = c10WebhookDeliveryFixtures();

    $row = c10BaseWebhookDeliveryRow($org, $project, $participant);
    $row['status'] = 'skipped';
    $row['skip_reason'] = 'no_webhook_url';
    $row['attempt_count'] = 1;

    expect(fn () => DB::table('webhook_deliveries')->insert($row))
        ->toThrow(QueryException::class);
});

test('CHECK constraint rejects attempt_count greater than max_attempts', function (): void {
    [$org, $project, $participant] = c10WebhookDeliveryFixtures();

    $row = c10BaseWebhookDeliveryRow($org, $project, $participant);
    $row['attempt_count'] = 7;
    $row['max_attempts'] = 6;

    expect(fn () => DB::table('webhook_deliveries')->insert($row))
        ->toThrow(QueryException::class);
});
