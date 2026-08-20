<?php

declare(strict_types=1);

/**
 * TavusLanguage — the it/en → spelled-out vocabulary Tavus expects
 * (avatar-language-follows-project, D3).
 *
 * Extracted from `TemplatePayload::tavus()` when the template stopped carrying a
 * language. It lives in its own class so the ratified vocabulary requirement
 * keeps a pure unit test instead of only being observable through an HTTP fake.
 */

use App\Support\Provider\TavusLanguage;

test('maps the two supported locales to the spelled-out form Tavus expects', function (): void {
    expect(TavusLanguage::forWire('it'))->toBe('italian');
    expect(TavusLanguage::forWire('en'))->toBe('english');
});

test('passes an unmapped value through unchanged rather than guessing', function (): void {
    // Tavus accepts a wrong value and ignores it silently. Substituting a default
    // here would turn an unsupported locale into a confidently wrong one.
    expect(TavusLanguage::forWire('fr'))->toBe('fr');
});
