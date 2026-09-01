<?php

declare(strict_types=1);

/**
 * A new backoffice user is told what BEAI is and what THEY can do in it.
 *
 * Creating a user used to be silent: the account appeared, and the person it
 * belonged to found out when somebody told them in a meeting. The invitation
 * is the first thing they see of this product, so it explains what the product
 * is before it explains their role — "you can review evaluations" means
 * nothing to someone who has never heard of BEAI.
 */

use App\Jobs\SendUserInvitationJob;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function invitationAdmin(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id, 'name' => 'Ada Lovelace']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

    // All three, not just the admin's own: `POST /api/users` resolves the
    // requested role by name, and an organization missing it answers 404 —
    // which reads as "the route is wrong" and is nothing of the kind.
    foreach (['admin', 'operator', 'viewer'] as $name) {
        SpatieRole::firstOrCreate(['name' => $name, 'guard_name' => 'api', 'team_id' => $org->id]);
    }

    $user->assignRole(SpatieRole::firstOrCreate([
        'name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id,
    ]));
    app(TenantResolver::class)->setOrgId($org->id);

    return auth('api')->login($user);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createUserVia(string $token, array $overrides = []): void
{
    test()->withToken($token)->postJson('/api/users', array_merge([
        'name' => 'Grace Hopper',
        'email' => 'grace@example.test',
        'password' => 'a-very-long-temporary-password',
        'role' => 'operator',
    ], $overrides))->assertCreated();
}

test('creating a user queues their invitation', function (): void {
    Queue::fake();

    $org = Organization::factory()->create(['name' => 'Acme Assessments']);
    $token = invitationAdmin($org);

    createUserVia($token);

    Queue::assertPushed(SendUserInvitationJob::class);
});

test('the invitation is QUEUED, never sent inline', function (): void {
    // The account was created successfully. Refusing to report that because a
    // mail provider is having a bad minute is the wrong failure to surface.
    Queue::fake();
    Notification::fake();

    $org = Organization::factory()->create();
    $token = invitationAdmin($org);

    createUserVia($token);

    Notification::assertNothingSent();
});

test('the invitation carries a set-password link and NEVER the password', function (): void {
    // `POST /api/users` takes a password from the admin creating the account,
    // so the obvious invitation repeats it back over email. A password in an
    // inbox is a password in every backup, forward and search index that inbox
    // is part of — and the admin who chose it already knows it, which is one
    // person too many.
    Notification::fake();
    config()->set('services.backoffice_origin', 'https://backoffice.test');

    $org = Organization::factory()->create();
    $token = invitationAdmin($org);
    createUserVia($token, ['password' => 'the-secret-nobody-should-mail']);

    $created = User::withoutGlobalScopes()->where('email', 'grace@example.test')->firstOrFail();

    (new SendUserInvitationJob($created->id, 'operator', 'Ada Lovelace', 'Acme'))->handle();

    Notification::assertSentTo($created, UserInvitationNotification::class, function ($notification) use ($created): bool {
        $rendered = $notification->toMail($created);

        // The rendered ARRAY, not its JSON: `json_encode` escapes `/` as `\/`,
        // so a URL assertion against the encoded string fails on a message
        // that is perfectly correct.
        $body = implode(' ', array_merge($rendered->introLines, $rendered->outroLines));

        expect($body)->not->toContain('the-secret-nobody-should-mail')
            ->and($rendered->actionUrl)->toStartWith('https://backoffice.test/reset-password/')
            ->and($body)->toContain('https://backoffice.test/reset-password/');

        return true;
    });
});

test('each role is told what IT can do, not merely what it is called', function (string $role, string $mustContain): void {
    // "You have been given the operator role" tells someone nothing they can
    // act on. Asserted per role because one paragraph with a substituted role
    // name would be vague for all three.
    Notification::fake();
    config()->set('services.backoffice_origin', 'https://backoffice.test');

    $org = Organization::factory()->create();
    invitationAdmin($org);
    $target = User::factory()->create(['organization_id' => $org->id, 'locale' => 'en']);

    (new SendUserInvitationJob($target->id, $role, 'Ada', 'Acme'))->handle();

    Notification::assertSentTo($target, UserInvitationNotification::class, function ($notification) use ($target, $mustContain): bool {
        $body = implode(' ', $notification->toMail($target)->introLines);

        expect($body)->toContain($mustContain);

        return true;
    });
})->with([
    ['admin', 'ADMINISTRATOR'],
    ['operator', 'OPERATOR'],
    ['viewer', 'OBSERVER'],
]);

test('an unrecognised role falls back to the MOST RESTRICTIVE description', function (): void {
    // Describing more power than someone has sends them looking for buttons
    // that are not there; understating merely gets corrected by the product on
    // their first visit.
    Notification::fake();
    config()->set('services.backoffice_origin', 'https://backoffice.test');

    $org = Organization::factory()->create();
    $target = User::factory()->create(['organization_id' => $org->id, 'locale' => 'en']);

    (new SendUserInvitationJob($target->id, 'some-future-role', 'Ada', 'Acme'))->handle();

    Notification::assertSentTo($target, UserInvitationNotification::class, function ($notification) use ($target): bool {
        $body = implode(' ', $notification->toMail($target)->introLines);

        expect($body)->toContain('OBSERVER')
            ->and($body)->not->toContain('ADMINISTRATOR');

        return true;
    });
});

test('it is written in the RECIPIENT\'s language, not the worker\'s', function (): void {
    // A queue worker runs in whatever locale it booted with. i18n is mandatory
    // it/en, and the person reading this has never interacted with the product
    // before — an invitation in the wrong language is the worst possible first
    // impression of a product that promises multilingual assessments.
    Notification::fake();
    config()->set('services.backoffice_origin', 'https://backoffice.test');
    app()->setLocale('en');

    $org = Organization::factory()->create();
    $target = User::factory()->create(['organization_id' => $org->id, 'locale' => 'it']);

    (new SendUserInvitationJob($target->id, 'operator', 'Ada', 'Acme'))->handle();

    Notification::assertSentTo($target, UserInvitationNotification::class, function ($notification): bool {
        expect($notification->locale)->toBe('it');

        return true;
    });
});

test('it refuses to send a broken link rather than sending one', function (): void {
    // An invitation whose link goes nowhere is worse than none: the recipient
    // believes they have been onboarded and stops looking for the reason they
    // cannot get in.
    Notification::fake();
    config()->set('services.backoffice_origin', null);

    $org = Organization::factory()->create();
    $target = User::factory()->create(['organization_id' => $org->id]);

    (new SendUserInvitationJob($target->id, 'operator', 'Ada', 'Acme'))->handle();

    Notification::assertNothingSent();
});

test('a user deleted before the job runs is skipped, not an error', function (): void {
    Notification::fake();
    config()->set('services.backoffice_origin', 'https://backoffice.test');

    $org = Organization::factory()->create();
    $target = User::factory()->create(['organization_id' => $org->id]);
    $id = $target->id;
    $target->forceDelete();

    (new SendUserInvitationJob($id, 'operator', 'Ada', 'Acme'))->handle();

    Notification::assertNothingSent();
});
