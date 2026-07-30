<?php

declare(strict_types=1);

/**
 * Schema-level guarantees for notification_logs (C12, D3).
 *
 * These assertions go through DB::table() rather than the Eloquent model on
 * purpose: the point is that the DATABASE arbitrates, not that application code
 * remembers to. A read-then-write in PHP would race; a unique index does not.
 */

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function insertNotificationLog(int $organizationId, array $overrides = []): void
{
    DB::table('notification_logs')->insert(array_merge([
        'organization_id' => $organizationId,
        'notification_type' => 'webhook_delivery_dead',
        'subject_type' => 'webhook_delivery',
        'subject_id' => 1,
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

test('a duplicate (org, type, subject_type, subject_id) raises a unique violation', function (): void {
    $org = Organization::factory()->create();

    insertNotificationLog($org->id);

    // 23505 = unique_violation. The recorder catches exactly this and lets the
    // EXISTING row's status decide what happens next.
    try {
        insertNotificationLog($org->id);
        $this->fail('The second insert should have violated the unique index.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23505');
    }
});

test('the same subject in a DIFFERENT organization is not a duplicate', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    insertNotificationLog($orgA->id);
    insertNotificationLog($orgB->id);

    // Two tenants can independently be told about their own subject id 1.
    // If organization_id were missing from the index this would collapse them,
    // and one tenant's alert would suppress another's.
    expect(DB::table('notification_logs')->count())->toBe(2);
});

test('a suppressed row without a reason is rejected', function (): void {
    $org = Organization::factory()->create();

    expect(fn () => insertNotificationLog($org->id, ['status' => 'suppressed']))
        ->toThrow(QueryException::class);
});

test('a non-suppressed row carrying a reason is rejected', function (): void {
    $org = Organization::factory()->create();

    // The CHECK is an equivalence, not an implication: a `sent` row with a
    // suppression_reason is just as illegal as a `suppressed` row without one.
    expect(fn () => insertNotificationLog($org->id, [
        'status' => 'pending',
        'suppression_reason' => 'window',
    ]))->toThrow(QueryException::class);
});

test('a sent row must carry sent_at, and only a sent row may', function (): void {
    $org = Organization::factory()->create();

    expect(fn () => insertNotificationLog($org->id, ['status' => 'sent']))
        ->toThrow(QueryException::class);

    expect(fn () => insertNotificationLog($org->id, [
        'subject_id' => 2,
        'status' => 'pending',
        'sent_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('users.locale exists, is nullable, and is mass-assignable', function (): void {
    expect(Schema::hasColumn('users', 'locale'))->toBeTrue();

    /** @var Model $user */
    $user = User::factory()->create(['locale' => null]);
    expect($user->locale)->toBeNull();

    // A preference, not a security attribute — so it belongs in $fillable.
    expect((new User)->getFillable())->toContain('locale');
});
