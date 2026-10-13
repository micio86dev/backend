<?php

declare(strict_types=1);

/**
 * The two guards in front of translatable catalogue content:
 *  - `ReadsCatalogueLocaleMaps` fails closed on untrusted seed/import JSON;
 *  - `ValidatesLocaleMaps` builds the FormRequest rules for the authoring API.
 * Both must agree: `en` is mandatory, only known locales are accepted, and a
 * value is never blank nor padded with edge whitespace.
 */

use App\Http\Requests\Catalogue\Concerns\ValidatesLocaleMaps;
use App\Support\Catalogue\Concerns\ReadsCatalogueLocaleMaps;
use Illuminate\Support\Facades\Validator;

function localeMapReader(): object
{
    return new class
    {
        use ReadsCatalogueLocaleMaps;

        /** @return array<string, string> */
        public function read(mixed $value, bool $allowBlankEn = false): array
        {
            return $this->readLocaleMap($value, 'competency [COL] name', 'catalogue:import', $allowBlankEn);
        }
    };
}

function localeMapValidator(array $data, bool $required = true): Illuminate\Validation\Validator
{
    $harness = new class
    {
        use ValidatesLocaleMaps;

        /** @return array<string, list<mixed>> */
        public function rulesFor(bool $required): array
        {
            return $this->localeMapRules('name', ['en', 'it'], $required);
        }
    };

    return Validator::make($data, $harness->rulesFor($required));
}

// ─── ReadsCatalogueLocaleMaps ────────────────────────────────────────────────

test('a valid locale map is returned unchanged', function (): void {
    expect(localeMapReader()->read(['en' => 'Teamwork', 'it' => 'Lavoro di squadra']))
        ->toBe(['en' => 'Teamwork', 'it' => 'Lavoro di squadra']);
});

test('anything that is not an explicit locale-map object is refused and names the source and field', function (mixed $value, string $got): void {
    expect(fn () => localeMapReader()->read($value))
        ->toThrow(RuntimeException::class, "catalogue:import: competency [COL] name must be a locale-map object (e.g. {\"en\": \"...\"}), got {$got}.");
})->with([
    'bare string' => ['Teamwork', 'string'],
    'null' => [null, 'null'],
    'a list' => [['Teamwork'], 'a list/array'],
]);

test('a map without a string en is refused', function (array $value): void {
    expect(fn () => localeMapReader()->read($value))->toThrow(RuntimeException::class, "missing a mandatory 'en' locale value");
})->with([
    'no en' => [['it' => 'Lavoro di squadra']],
    'non-string en' => [['en' => 5]],
]);

test('a blank en is refused unless the field explicitly allows the not-yet-authored sentinel', function (): void {
    expect(fn () => localeMapReader()->read(['en' => '']))->toThrow(RuntimeException::class, "blank 'en' locale value");

    expect(localeMapReader()->read(['en' => ''], allowBlankEn: true))->toBe(['en' => '']);
});

test('a locale outside the supported set is refused', function (): void {
    expect(fn () => localeMapReader()->read(['en' => 'Teamwork', 'fr' => 'Travail']))
        ->toThrow(RuntimeException::class, 'unknown locale key [fr]. Known locales: it, en.');
});

test('a non-string translation is refused and names the locale', function (): void {
    expect(fn () => localeMapReader()->read(['en' => 'Teamwork', 'it' => ['Lavoro']]))
        ->toThrow(RuntimeException::class, 'locale [it] must be a string, got array');
});

test('en is always a known locale even when the configured list omits it', function (): void {
    config(['app.supported_locales' => ['it']]);

    expect(localeMapReader()->read(['en' => 'Teamwork', 'it' => 'Lavoro']))->toHaveKey('en');
});

// ─── ValidatesLocaleMaps ─────────────────────────────────────────────────────

test('a complete map passes', function (): void {
    expect(localeMapValidator(['name' => ['en' => 'Teamwork', 'it' => 'Lavoro di squadra']])->passes())->toBeTrue();
});

test('a required map needs en', function (): void {
    $v = localeMapValidator(['name' => ['it' => 'Lavoro di squadra']]);

    expect($v->passes())->toBeFalse()->and($v->errors()->keys())->toContain('name.en');
});

test('an optional map may be absent, but once present it needs en', function (): void {
    expect(localeMapValidator([], required: false)->passes())->toBeTrue();

    $v = localeMapValidator(['name' => ['it' => 'Lavoro di squadra']], required: false);
    expect($v->passes())->toBeFalse()->and($v->errors()->keys())->toContain('name.en');
});

test('an unknown locale key is refused with a stable machine code', function (): void {
    $v = localeMapValidator(['name' => ['en' => 'Teamwork', 'fr' => 'Travail']]);

    expect($v->passes())->toBeFalse()->and($v->errors()->get('name'))->toBe(['name_unknown_locale']);
});

test('a map that is not an array is refused without tripping the locale check', function (): void {
    $v = localeMapValidator(['name' => 'Teamwork']);

    expect($v->passes())->toBeFalse()->and($v->errors()->get('name'))->not->toContain('name_unknown_locale');
});

test('a whitespace-only en is refused as missing and a padded value is refused with the edge-whitespace code', function (): void {
    $blank = localeMapValidator(['name' => ['en' => '   ']]);
    $padded = localeMapValidator(['name' => ['en' => ' Teamwork ']]);
    $paddedIt = localeMapValidator(['name' => ['en' => 'Teamwork', 'it' => 'Lavoro ']]);

    expect($blank->fails())->toBeTrue()
        ->and($blank->errors()->keys())->toBe(['name.en'])
        ->and($padded->errors()->get('name.en'))->toBe(['name.en_edge_whitespace'])
        ->and($paddedIt->errors()->get('name.it'))->toBe(['name.it_edge_whitespace']);
});

test('an explicit null for an optional translation is refused rather than stored', function (): void {
    $v = localeMapValidator(['name' => ['en' => 'Teamwork', 'it' => null]]);

    expect($v->fails())->toBeTrue()->and($v->errors()->keys())->toBe(['name.it']);
});
