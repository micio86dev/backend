<?php

/**
 * RED — 8.6: CompetencyNormalizer handles split and unified shapes (C3).
 * RED — Phase 2.1 (`framework-catalog-it-translations`): the normalizer now
 * targets the explicit locale-map leaf shape (design D1): every translatable
 * field is `{"en": "...", "it": "..."}`, `en` mandatory, unknown locale keys
 * rejected, a bare string rejected outright — a guard that has not been
 * updated for the new shape must STOP READING rather than pass silently.
 *
 * Feeds both shapes; asserts identical DTO output.
 * Refs spec: "Split-file shape" + "Unified shape produces the same DB state";
 * design.md D1 + tasks.md Phase 2.
 */

use App\Services\FrameworkCatalog\CompetencyNormalizer;
use App\Services\FrameworkCatalog\DTO\IndicatorDTO;

test('normalizer handles split shape (competency entry + bars array)', function (): void {
    $competencyEntry = [
        'code' => 'PRS',
        'name' => ['en' => 'Problem Solving'],
        'definition' => ['en' => 'Gather and analyze...'],
        'type' => 'standard',
    ];

    $barsArray = [
        [
            'indicator' => ['en' => 'Recognizes symptoms that indicate problems.'],
            'scale' => [
                '5' => ['en' => 'Anchor 5 text'],
                '3' => ['en' => 'Anchor 3 text'],
                '1' => ['en' => 'Anchor 1 text'],
            ],
        ],
        [
            'indicator' => ['en' => 'Generates solution hypotheses.'],
            'scale' => [
                '5' => ['en' => 'Anchor 5 text 2'],
                '3' => ['en' => 'Anchor 3 text 2'],
                '1' => ['en' => 'Anchor 1 text 2'],
            ],
        ],
    ];

    $normalizer = new CompetencyNormalizer;
    $dto = $normalizer->normalize($competencyEntry, $barsArray);

    expect($dto->code)->toBe('PRS')
        ->and($dto->name)->toBe(['en' => 'Problem Solving'])
        ->and($dto->definition)->toBe(['en' => 'Gather and analyze...'])
        ->and($dto->type)->toBe('standard')
        ->and($dto->indicators)->toHaveCount(2);

    $firstIndicator = $dto->indicators[0];
    expect($firstIndicator)->toBeInstanceOf(IndicatorDTO::class)
        ->and($firstIndicator->text)->toBe(['en' => 'Recognizes symptoms that indicate problems.'])
        ->and($firstIndicator->anchor5)->toBe(['en' => 'Anchor 5 text'])
        ->and($firstIndicator->anchor3)->toBe(['en' => 'Anchor 3 text'])
        ->and($firstIndicator->anchor1)->toBe(['en' => 'Anchor 1 text'])
        ->and($firstIndicator->position)->toBe(0);

    $secondIndicator = $dto->indicators[1];
    expect($secondIndicator->position)->toBe(1);
});

test('normalizer handles unified shape (competency entry WITH embedded bars)', function (): void {
    $unifiedEntry = [
        'code' => 'PRS',
        'name' => ['en' => 'Problem Solving'],
        'definition' => ['en' => 'Gather and analyze...'],
        'type' => 'standard',
        'bars' => [ // unified shape: bars embedded in competency entry
            [
                'indicator' => ['en' => 'Recognizes symptoms that indicate problems.'],
                'scale' => [
                    '5' => ['en' => 'Anchor 5 text'],
                    '3' => ['en' => 'Anchor 3 text'],
                    '1' => ['en' => 'Anchor 1 text'],
                ],
            ],
        ],
    ];

    $normalizer = new CompetencyNormalizer;
    // Unified shape: pass null for $barsArray — normalizer detects 'bars' key in competencyEntry
    $dto = $normalizer->normalize($unifiedEntry, null);

    expect($dto->code)->toBe('PRS')
        ->and($dto->name)->toBe(['en' => 'Problem Solving'])
        ->and($dto->indicators)->toHaveCount(1);

    expect($dto->indicators[0]->text)->toBe(['en' => 'Recognizes symptoms that indicate problems.'])
        ->and($dto->indicators[0]->anchor5)->toBe(['en' => 'Anchor 5 text'])
        ->and($dto->indicators[0]->position)->toBe(0);
});

test('normalizer produces same DTO from split and unified shapes for identical data', function (): void {
    $competencyEntry = [
        'code' => 'COM',
        'name' => ['en' => 'Communication'],
        'definition' => ['en' => 'Communicate effectively...'],
        'type' => 'standard',
    ];

    $barsArray = [
        [
            'indicator' => ['en' => 'Indicator 1'],
            'scale' => ['5' => ['en' => 'A5'], '3' => ['en' => 'A3'], '1' => ['en' => 'A1']],
        ],
    ];

    $unifiedEntry = array_merge($competencyEntry, ['bars' => $barsArray]);

    $normalizer = new CompetencyNormalizer;
    $splitDto = $normalizer->normalize($competencyEntry, $barsArray);
    $unifiedDto = $normalizer->normalize($unifiedEntry, null);

    expect($splitDto->code)->toBe($unifiedDto->code)
        ->and($splitDto->name)->toBe($unifiedDto->name)
        ->and(count($splitDto->indicators))->toBe(count($unifiedDto->indicators))
        ->and($splitDto->indicators[0]->text)->toBe($unifiedDto->indicators[0]->text)
        ->and($splitDto->indicators[0]->anchor5)->toBe($unifiedDto->indicators[0]->anchor5);
});

test('normalizer carries both locales through when both are present', function (): void {
    $competencyEntry = [
        'code' => 'PRS',
        'name' => ['en' => 'Problem Solving', 'it' => 'Problem Solving IT'],
        'definition' => ['en' => 'Gather and analyze...', 'it' => 'Raccogliere e analizzare...'],
        'type' => 'standard',
    ];
    $barsArray = [
        [
            'indicator' => ['en' => 'Recognize symptoms.', 'it' => 'Individuare i sintomi.'],
            'scale' => [
                '5' => ['en' => 'A5 en', 'it' => 'A5 it'],
                '3' => ['en' => 'A3 en', 'it' => 'A3 it'],
                '1' => ['en' => 'A1 en', 'it' => 'A1 it'],
            ],
        ],
    ];

    $dto = (new CompetencyNormalizer)->normalize($competencyEntry, $barsArray);

    expect($dto->name)->toBe(['en' => 'Problem Solving', 'it' => 'Problem Solving IT'])
        ->and($dto->indicators[0]->text)->toBe(['en' => 'Recognize symptoms.', 'it' => 'Individuare i sintomi.'])
        ->and($dto->indicators[0]->anchor5)->toBe(['en' => 'A5 en', 'it' => 'A5 it']);
});

test('normalizer REJECTS a bare-string field — the old flat shape must fail closed', function (): void {
    $competencyEntry = [
        'code' => 'PRS',
        'name' => 'Problem Solving', // bare string — pre-migration shape
        'definition' => ['en' => 'Gather and analyze...'],
        'type' => 'standard',
    ];

    (new CompetencyNormalizer)->normalize($competencyEntry, []);
})->throws(InvalidArgumentException::class);

test('normalizer REJECTS a bare-string bars indicator field', function (): void {
    $barsArray = [
        [
            'indicator' => 'Recognizes symptoms that indicate problems.', // bare string
            'scale' => [
                '5' => ['en' => 'A5'],
                '3' => ['en' => 'A3'],
                '1' => ['en' => 'A1'],
            ],
        ],
    ];

    (new CompetencyNormalizer)->normalize(['code' => 'PRS', 'name' => ['en' => 'x'], 'definition' => ['en' => 'y']], $barsArray);
})->throws(InvalidArgumentException::class);

test('normalizer REJECTS a bare-string scale anchor value', function (): void {
    $barsArray = [
        [
            'indicator' => ['en' => 'Recognizes symptoms.'],
            'scale' => [
                '5' => 'Anchor 5 text', // bare string
                '3' => ['en' => 'A3'],
                '1' => ['en' => 'A1'],
            ],
        ],
    ];

    (new CompetencyNormalizer)->normalize(['code' => 'PRS', 'name' => ['en' => 'x'], 'definition' => ['en' => 'y']], $barsArray);
})->throws(InvalidArgumentException::class);

test('normalizer REJECTS a locale map missing the mandatory en key', function (): void {
    $barsArray = [
        [
            'indicator' => ['it' => 'Individuare i sintomi.'], // no 'en'
            'scale' => [
                '5' => ['en' => 'A5'],
                '3' => ['en' => 'A3'],
                '1' => ['en' => 'A1'],
            ],
        ],
    ];

    (new CompetencyNormalizer)->normalize(['code' => 'PRS', 'name' => ['en' => 'x'], 'definition' => ['en' => 'y']], $barsArray);
})->throws(InvalidArgumentException::class);

test('normalizer REJECTS an unknown locale key', function (): void {
    $barsArray = [
        [
            'indicator' => ['en' => 'Recognize symptoms.', 'fr' => 'Reconnaître les symptômes.'], // unknown locale
            'scale' => [
                '5' => ['en' => 'A5'],
                '3' => ['en' => 'A3'],
                '1' => ['en' => 'A1'],
            ],
        ],
    ];

    (new CompetencyNormalizer)->normalize(['code' => 'PRS', 'name' => ['en' => 'x'], 'definition' => ['en' => 'y']], $barsArray);
})->throws(InvalidArgumentException::class);
