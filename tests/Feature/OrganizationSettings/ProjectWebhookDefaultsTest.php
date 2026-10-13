<?php

declare(strict_types=1);

/**
 * `ProjectWebhookDefaults::apply()` decides key by key: a default fills a key
 * only when the caller did not send it at all, and only when the organization
 * actually has a default for it.
 */

use App\Models\Organization;
use App\Support\Projects\ProjectWebhookDefaults;
use Illuminate\Http\Request;

function orgWithWebhookDefaults(array $attributes = []): Organization
{
    return Organization::factory()->create($attributes + [
        'default_webhook_url' => 'https://org.example.test/hook',
        'default_webhook_secret' => 'org-secret-value',
        'default_webhook_events' => ['progress'],
    ]);
}

test('every key the request omits is filled from the org defaults, the secret included', function (): void {
    $payload = (new ProjectWebhookDefaults)->apply(['name' => 'P'], Request::create('/', 'POST', ['name' => 'P']), orgWithWebhookDefaults());

    expect($payload)->toMatchArray([
        'name' => 'P',
        'webhook_url' => 'https://org.example.test/hook',
        'webhook_secret' => 'org-secret-value',
        'webhook_events' => ['progress'],
    ]);
});

test('a key present in the request, even as null, is never replaced by the default', function (): void {
    $sent = ['webhook_url' => null, 'webhook_secret' => null, 'webhook_events' => null];

    $payload = (new ProjectWebhookDefaults)->apply($sent, Request::create('/', 'POST', $sent), orgWithWebhookDefaults());

    expect($payload)->toBe($sent);
});

test('an organization without defaults leaves the payload untouched', function (): void {
    $org = orgWithWebhookDefaults([
        'default_webhook_url' => null,
        'default_webhook_secret' => null,
        'default_webhook_events' => null,
    ]);

    $payload = (new ProjectWebhookDefaults)->apply(['name' => 'P'], Request::create('/', 'POST', ['name' => 'P']), $org);

    expect($payload)->toBe(['name' => 'P']);
});
