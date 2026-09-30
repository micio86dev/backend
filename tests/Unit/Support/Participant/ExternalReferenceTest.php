<?php

declare(strict_types=1);

/**
 * RED — candidate-external-reference A1.1: the `ExternalReference` value
 * object that owns the validation rules, the normalisation, the JWT-claim
 * form and the column form of the optional `external_id` + `source` pair
 * (design AD-1, AD-2).
 *
 * Pure logic, no database: the rule set is exercised through a bare
 * Validator so the tests prove what the rules ACCEPT and REFUSE, not merely
 * what strings they contain.
 */

use App\Support\Participant\ExternalReference;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Tests\Helpers\ExternalReferenceCases;

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, list<string>>  $rules
 * @return list<string> the failing field names
 */
function externalReferenceFailures(array $data, array $rules): array
{
    $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($data, $rules);

    return array_keys($validator->errors()->toArray());
}

test('the two limits are the JS safe-integer cap and 180 characters', function (): void {
    expect(ExternalReference::MAX_EXTERNAL_ID)->toBe(9007199254740991);
    expect(ExternalReference::SOURCE_MAX_LENGTH)->toBe(180);
});

describe('rules()', function (): void {
    test('are the AD-2 rule set keyed by the bare field names', function (): void {
        expect(ExternalReference::rules())->toBe([
            'external_id' => ['sometimes', 'nullable', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'source' => ['sometimes', 'nullable', 'string', 'max:180'],
        ]);
    });

    test('carry the prefix on the keys and nowhere else', function (): void {
        $prefixed = ExternalReference::rules('candidate.');

        expect(array_keys($prefixed))->toBe(['candidate.external_id', 'candidate.source']);
        expect(array_values($prefixed))->toBe(array_values(ExternalReference::rules()));
    });

    test('refuse every invalid value and name the offending field', function (string $field, mixed $value): void {
        expect(externalReferenceFailures([$field => $value], ExternalReference::rules()))->toBe([$field]);
    })->with(fn (): array => ExternalReferenceCases::invalid());

    test('accept the boundaries, null, and an omitted field', function (): void {
        $rules = ExternalReference::rules();

        expect(externalReferenceFailures(['external_id' => 1], $rules))->toBe([]);
        expect(externalReferenceFailures(['external_id' => ExternalReference::MAX_EXTERNAL_ID], $rules))->toBe([]);
        expect(externalReferenceFailures(['source' => str_repeat('a', 180)], $rules))->toBe([]);
        expect(externalReferenceFailures(['source' => str_repeat('è', 180)], $rules))->toBe([]);
        expect(externalReferenceFailures(['external_id' => null, 'source' => null], $rules))->toBe([]);
        expect(externalReferenceFailures([], $rules))->toBe([]);
    });

    test('validate the prefixed keys against a nested payload', function (): void {
        $rules = ExternalReference::rules('candidate.');

        expect(externalReferenceFailures(['candidate' => ['external_id' => 0]], $rules))->toBe(['candidate.external_id']);
        expect(externalReferenceFailures(['candidate' => ['external_id' => 7, 'source' => 'hr']], $rules))->toBe([]);
    });
});

describe('fromValidated()', function (): void {
    test('keeps an integer id and trims the source', function (): void {
        $reference = ExternalReference::fromValidated(['external_id' => 4471, 'source' => '  acme-ats  ']);

        expect($reference->externalId)->toBe(4471);
        expect($reference->source)->toBe('acme-ats');
    });

    test('turns an empty or whitespace-only source into null (R3)', function (string $source): void {
        expect(ExternalReference::fromValidated(['source' => $source])->source)->toBeNull();
    })->with(['empty' => [''], 'spaces' => ['   '], 'tab and newline' => ["\t\n"]]);

    test('treats an absent or null field as null', function (): void {
        expect(ExternalReference::fromValidated([])->isEmpty())->toBeTrue();
        expect(ExternalReference::fromValidated(['external_id' => null, 'source' => null])->isEmpty())->toBeTrue();
    });

    test('ignores keys that are not its own', function (): void {
        $reference = ExternalReference::fromValidated(['external_id' => 9, 'email' => 'a@b.c', 'candidate_ref' => 'x']);

        expect($reference->toAttributes())->toBe(['external_id' => 9, 'source' => null]);
    });

    test('round-trips every valid combination', function (?int $externalId, ?string $source): void {
        $reference = ExternalReference::fromValidated(['external_id' => $externalId, 'source' => $source]);

        expect($reference->externalId)->toBe($externalId);
        expect($reference->source)->toBe($source);
    })->with(fn (): array => ExternalReferenceCases::validCombinations());
});

describe('fromClaims()', function (): void {
    test('accepts a well-formed pair', function (): void {
        $reference = ExternalReference::fromClaims(4471, 'acme-ats');

        expect($reference->externalId)->toBe(4471);
        expect($reference->source)->toBe('acme-ats');
    });

    test('accepts the boundaries', function (): void {
        expect(ExternalReference::fromClaims(1, null)->externalId)->toBe(1);
        expect(ExternalReference::fromClaims(ExternalReference::MAX_EXTERNAL_ID, null)->externalId)->toBe(ExternalReference::MAX_EXTERNAL_ID);
        expect(ExternalReference::fromClaims(null, str_repeat('a', 180))->source)->toBe(str_repeat('a', 180));
    });

    test('narrows a malformed external_id claim to null, never throwing (R2)', function (mixed $claim): void {
        expect(ExternalReference::fromClaims($claim, 'acme-ats'))
            ->externalId->toBeNull()
            ->source->toBe('acme-ats');
    })->with([
        'numeric string' => ['12'],
        'float' => [12.0],
        'boolean true' => [true],
        'zero' => [0],
        'negative' => [-5],
        'above the cap' => [9007199254740992],
        'array' => [[1]],
        'null' => [null],
    ]);

    test('narrows a malformed source claim to null, never throwing (R2, R3)', function (mixed $claim): void {
        expect(ExternalReference::fromClaims(4471, $claim))
            ->externalId->toBe(4471)
            ->source->toBeNull();
    })->with([
        'integer' => [42],
        'boolean' => [false],
        'array' => [['a']],
        'empty string' => [''],
        'whitespace only' => ['   '],
        'over the length cap' => [str_repeat('a', 181)],
        'null' => [null],
    ]);

    test('trims a padded source claim', function (): void {
        expect(ExternalReference::fromClaims(null, '  acme  ')->source)->toBe('acme');
    });
});

describe('parseExternalIdTerm()', function (): void {
    test('returns the integer for an all-digit term inside 1..MAX', function (string $term, int $expected): void {
        expect(ExternalReference::parseExternalIdTerm($term))->toBe($expected);
    })->with([
        'one' => ['1', 1],
        'plain' => ['4471', 4471],
        'leading zeros' => ['0042', 42],
        'the cap' => ['9007199254740991', 9007199254740991],
    ]);

    test('returns null for anything that is not a usable external id', function (string $term): void {
        expect(ExternalReference::parseExternalIdTerm($term))->toBeNull();
    })->with([
        'zero' => ['0'],
        'all zeros' => ['000'],
        'letters' => ['abc'],
        'mixed' => ['44x'],
        'signed' => ['-5'],
        'decimal' => ['1.5'],
        'empty' => [''],
        'padded' => [' 12'],
        'one past the cap' => ['9007199254740992'],
        'seventeen digits' => ['10000000000000000'],
        'absurdly long' => ['99999999999999999999999'],
    ]);
});

describe('isEmpty()', function (): void {
    test('is true only when both parts are null', function (): void {
        expect((new ExternalReference)->isEmpty())->toBeTrue();
        expect((new ExternalReference(externalId: 1))->isEmpty())->toBeFalse();
        expect((new ExternalReference(source: 'hr'))->isEmpty())->toBeFalse();
        expect((new ExternalReference(1, 'hr'))->isEmpty())->toBeFalse();
    });
});

describe('toClaims()', function (): void {
    test('carries only the non-null keys, so a token without a reference is byte-identical to today', function (): void {
        expect((new ExternalReference)->toClaims())->toBe([]);
        expect((new ExternalReference(externalId: 4471))->toClaims())->toBe(['external_id' => 4471]);
        expect((new ExternalReference(source: 'hr'))->toClaims())->toBe(['source' => 'hr']);
        expect((new ExternalReference(4471, 'hr'))->toClaims())->toBe(['external_id' => 4471, 'source' => 'hr']);
    });
});

describe('toAttributes()', function (): void {
    test('always carries both columns, null included, ready for forceFill()', function (): void {
        expect((new ExternalReference)->toAttributes())->toBe(['external_id' => null, 'source' => null]);
        expect((new ExternalReference(4471, 'hr'))->toAttributes())->toBe(['external_id' => 4471, 'source' => 'hr']);
    });
});
