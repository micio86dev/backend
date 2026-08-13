<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateOrganizationRequest (backoffice-missing-pages D2/D3).
 *
 * Validates PATCH /api/organization. Accepts `name` and the three
 * `default_webhook_*` fields; `slug` is deliberately NOT a rule here — it is
 * a tenancy identifier, never editable, and the controller only ever writes
 * `$request->safe()->only([...])`, so a `slug` key in the body is silently
 * dropped rather than validated-then-rejected.
 */
class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();
        $organization = Organization::find($user?->organization_id);

        return $organization !== null && ($user?->can('update', $organization) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'default_webhook_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'default_webhook_secret' => ['sometimes', 'nullable', 'string', 'max:1024'],
            // Closed event-type set — mirrors UpdateProjectRequest.php:94's
            // config-driven Rule::in (never a hardcoded list, never env-overridable).
            'default_webhook_events' => ['sometimes', 'nullable', 'array'],
            'default_webhook_events.*' => [Rule::in(config('webhooks.events.types'))],
        ];
    }
}
