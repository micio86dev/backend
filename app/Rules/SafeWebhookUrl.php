<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Webhooks\OutboundHostGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * SafeWebhookUrl — submission-time SSRF guard for `webhook_url` /
 * `default_webhook_url` (webhook-ssrf-guard, design.md D2).
 *
 * Additive to the existing `url`/`max:2048` rules, never a replacement — this only
 * adds the scheme/network-address restriction `App\Services\Webhooks\OutboundHostGuard`
 * enforces. `$fail()` is passed the literal slug `webhook_url_unsafe`, not a
 * translated sentence, matching this field's existing short-slug message convention
 * (`ValidatesProjectComposition::messages()`: `webhook_url_invalid`,
 * `webhook_url_too_long`).
 *
 * `OutboundHostGuard` is instantiated directly — it is stateless and dependency-free
 * (see its own class doc), so no container resolution is needed here.
 *
 * REQ: webhooks-integration — "Outbound target validation — SSRF guard"
 */
final class SafeWebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if ((new OutboundHostGuard)->isBlocked($value)) {
            $fail('webhook_url_unsafe');
        }
    }
}
