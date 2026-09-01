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

            /*
             * The primary colour is CSS, and is validated as CSS rather than as
             * a string that happens to look like one.
             *
             * It is interpolated into a custom property in both Nuxt apps, so a
             * value that is not a colour becomes a stylesheet that silently
             * does not apply, and one carrying `;` or `}` appends rules of its
             * own to every page an operator's candidates load.
             *
             * `\z`, NOT `$`, and that difference is the whole security of this
             * rule. In PCRE `$` also matches immediately BEFORE a final
             * newline, so `/^#[0-9a-f]{6}$/` accepts `"#123456\n"` — and the
             * newline is exactly what lets a payload open a second declaration
             * once it is interpolated into a stylesheet. `\z` matches only at
             * the true end of the subject. Caught by the injection test, which
             * failed against the `$` version of this rule.
             *
             * Three-digit shorthand is deliberately refused so the apps never
             * have to expand it — one canonical form, and an operator pasting
             * `#abc` is told so rather than getting a colour that quietly
             * differs from the one they copied.
             *
             * `nullable` is the route back to the product palette; without it,
             * choosing a colour would be a one-way door.
             */
            'primary_color' => ['sometimes', 'nullable', 'string', 'regex:/\A#[0-9a-fA-F]{6}\z/'],
        ];
    }
}
