<?php

declare(strict_types=1);

/**
 * The provider field specs and their validation (C14 PR3).
 *
 * One declarative definition drives three things that must never disagree: the
 * backoffice form, the API's validation, and the payload actually sent to the
 * provider. Written three times they drift, and the drift is invisible — a form
 * offering a knob the payload never sends looks exactly like a knob that does
 * not work.
 */

use App\Support\AvatarTemplates\ConfigValidator;
use App\Support\AvatarTemplates\ProviderFieldSpecs;

test('both providers publish a non-empty field spec', function (): void {
    expect(ProviderFieldSpecs::for('heygen'))->not->toBeEmpty();
    expect(ProviderFieldSpecs::for('tavus'))->not->toBeEmpty();
});

test('an unknown provider yields no fields rather than an exception', function (): void {
    // Called from a request path where the provider string has already been
    // validated. Returning [] keeps a bad value from becoming a 500 on a
    // surface whose job is to report bad values politely.
    expect(ProviderFieldSpecs::for('nope'))->toBe([]);
});

test('every field carries a label key, never a literal', function (): void {
    foreach (['heygen', 'tavus'] as $provider) {
        foreach (ProviderFieldSpecs::for($provider) as $field) {
            // The backoffice is mandatorily bilingual. A field spec that
            // shipped English labels would put untranslatable strings in an
            // Italian operator's form — and nothing would fail, it would just
            // be wrong in one language.
            expect($field->labelKey)->toStartWith('avatar_templates.field.');
        }
    }
});

test('field keys are unique within a provider', function (): void {
    foreach (['heygen', 'tavus'] as $provider) {
        $keys = array_map(fn ($f) => $f->key, ProviderFieldSpecs::for($provider));

        // A duplicate key means the second definition silently wins in the form
        // and the first one silently wins in the mapping, or vice versa.
        expect($keys)->toBe(array_values(array_unique($keys)));
    }
});

test('the identity knobs are required for each provider', function (): void {
    $required = fn (string $p) => array_map(
        fn ($f) => $f->key,
        array_filter(ProviderFieldSpecs::for($p), fn ($f) => $f->required),
    );

    // Without these there is no avatar and no voice — the provider falls back
    // to whatever the account defaults to, which is precisely the state this
    // whole change exists to end.
    expect(array_values($required('heygen')))->toBe(['avatarId', 'voiceId']);
    expect(array_values($required('tavus')))->toBe(['faceId', 'palId']);
});

test('persona-level knobs are marked, conversation-level ones are not', function (): void {
    $tavus = collect(ProviderFieldSpecs::for('tavus'))->keyBy('key');

    // Tavus splits its configuration across two API objects. A persona knob
    // sent on a conversation is silently ignored — no error, no effect, and an
    // operator who set it watching it do nothing.
    expect($tavus['llmModel']->palPath)->toBe('layers/llm/model');
    expect($tavus['faceId']->palPath)->toBeNull();
});

test('the dead voiceProvider knob is NOT ported', function (): void {
    $keys = array_map(fn ($f) => $f->key, ProviderFieldSpecs::for('heygen'));

    // avatar-tester collects this field and never sends it — its own notes say
    // so. Porting it would give an operator a control that does nothing, which
    // is worse than not offering the control: they would configure it, see no
    // change, and reasonably conclude the whole template feature is broken.
    expect($keys)->not->toContain('voiceProvider');
});

// ─── Validation ──────────────────────────────────────────────────────────────

test('a complete config validates clean', function (): void {
    $errors = ConfigValidator::validate('heygen', [
        'avatarId' => 'av_1',
        'voiceId' => 'vo_1',
        'language' => 'it',
        'voiceSpeed' => 1.0,
    ]);

    expect($errors)->toBe([]);
});

test('a missing required field is reported by key', function (): void {
    $errors = ConfigValidator::validate('heygen', ['voiceId' => 'vo_1']);

    expect($errors)->toHaveCount(1);
    expect($errors[0]['key'])->toBe('avatarId');
    expect($errors[0]['code'])->toBe('required');
});

test('an out-of-range number is reported as range, not as a type error', function (): void {
    $errors = ConfigValidator::validate('heygen', [
        'avatarId' => 'a', 'voiceId' => 'v', 'voiceSpeed' => 9.0,
    ]);

    // Distinguished on purpose: "not a number" and "a number we cannot use"
    // need different words in front of an operator, and a single generic
    // "invalid" leaves them guessing which.
    expect($errors[0]['code'])->toBe('range');
    expect($errors[0]['key'])->toBe('voiceSpeed');
});

test('a value outside a select is reported as enum', function (): void {
    $errors = ConfigValidator::validate('heygen', [
        'avatarId' => 'a', 'voiceId' => 'v', 'videoQuality' => 'ultra',
    ]);

    expect($errors[0]['code'])->toBe('enum');
});

test('a wrong scalar type is reported as type', function (): void {
    $errors = ConfigValidator::validate('heygen', [
        'avatarId' => 'a', 'voiceId' => 'v', 'voiceUseSpeakerBoost' => 'yes',
    ]);

    expect($errors[0]['code'])->toBe('type');
});

test('an unknown key is rejected rather than quietly stored', function (): void {
    $errors = ConfigValidator::validate('heygen', [
        'avatarId' => 'a', 'voiceId' => 'v', 'avatarID' => 'typo',
    ]);

    // The column is schemaless, so an unrecognised key would be stored happily
    // and never sent anywhere. An operator who mistypes a field name deserves
    // to be told, not to spend an afternoon wondering why their setting has no
    // effect.
    expect($errors[0]['code'])->toBe('unknown');
    expect($errors[0]['key'])->toBe('avatarID');
});

test('absent optional fields are not errors', function (): void {
    // Every optional knob left unset means "use the provider default", which is
    // the correct behaviour for a template that only wants to pin an avatar.
    expect(ConfigValidator::validate('heygen', ['avatarId' => 'a', 'voiceId' => 'v']))->toBe([]);
});

test('a null value is treated as absent, not as a type error', function (): void {
    // A form that clears a field posts null. Treating that as a type violation
    // would make "unset this knob" impossible through the UI.
    expect(ConfigValidator::validate('heygen', [
        'avatarId' => 'a', 'voiceId' => 'v', 'videoQuality' => null,
    ]))->toBe([]);
});

test('an integer is accepted where a float is expected', function (): void {
    // JSON gives 1 for 1.0. Rejecting it would mean a config that validated on
    // save fails on read, after the template is already live.
    expect(ConfigValidator::validate('heygen', [
        'avatarId' => 'a', 'voiceId' => 'v', 'voiceSpeed' => 1,
    ]))->toBe([]);
});

test('every error names its field', function (): void {
    $errors = ConfigValidator::validate('tavus', ['llmTemperature' => 99]);

    // Two problems here — missing faceId/palId and a bad temperature. All of
    // them must come back at once: reporting one error per round trip turns
    // filling in a form into a guessing game.
    expect(count($errors))->toBeGreaterThan(1);

    foreach ($errors as $error) {
        expect($error['key'])->not->toBe('');
    }
});
