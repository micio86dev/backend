<?php

declare(strict_types=1);

namespace App\Support\Projects;

use App\Models\Organization;
use Illuminate\Http\Request;

/**
 * Applies org-level webhook defaults to a Project creation payload
 * (backoffice-missing-pages D3 — copy-on-create, never a runtime fallback).
 *
 * Fills a key ONLY when the request payload does not contain it at all —
 * `$request->exists($key)`, never `filled()`. An operator who explicitly
 * sends `"webhook_url": null` means "this project has no webhook" and must
 * not have the org default silently reinstated; `filled()` cannot tell those
 * two states apart (both look "empty") and would.
 *
 * Delivery-time resolution (webhooks-integration's "Secret resolution —
 * Eloquent-only, never exposed") is not read, not called, not modified by
 * this class. It only ever runs once, before Project::create(), inside
 * ProjectController::store.
 */
final class ProjectWebhookDefaults
{
    /**
     * Merge org defaults into a Project creation payload, for keys the
     * caller did not supply at all.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload, Request $request, Organization $organization): array
    {
        if (! $request->exists('webhook_url') && $organization->default_webhook_url !== null) {
            $payload['webhook_url'] = $organization->default_webhook_url;
        }

        if (! $request->exists('webhook_secret') && $organization->default_webhook_secret !== null) {
            $payload['webhook_secret'] = $organization->default_webhook_secret;
        }

        if (! $request->exists('webhook_events') && $organization->default_webhook_events !== null) {
            $payload['webhook_events'] = $organization->default_webhook_events;
        }

        return $payload;
    }
}
