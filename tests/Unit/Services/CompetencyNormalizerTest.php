<?php

/**
 * RED — 8.6: CompetencyNormalizer handles split and unified shapes (C3).
 *
 * Feeds both shapes; asserts identical DTO output.
 * Refs spec: "Split-file shape" + "Unified shape produces the same DB state".
 */

use App\Services\FrameworkCatalog\CompetencyNormalizer;
use App\Services\FrameworkCatalog\DTO\IndicatorDTO;

test('normalizer handles split shape (competency entry + bars array)', function (): void {
    $competencyEntry = [
        'code' => 'PRS',
        'name' => 'Problem Solving',
        'definition' => 'Gather and analyze...',
        'type' => 'standard',
    ];

    $barsArray = [
        [
            'indicator' => 'Recognizes symptoms that indicate problems.',
            'scale' => [
                '5' => 'Anchor 5 text',
                '3' => 'Anchor 3 text',
                '1' => 'Anchor 1 text',
            ],
        ],
        [
            'indicator' => 'Generates solution hypotheses.',
            'scale' => [
                '5' => 'Anchor 5 text 2',
                '3' => 'Anchor 3 text 2',
                '1' => 'Anchor 1 text 2',
            ],
        ],
    ];

    $normalizer = new CompetencyNormalizer;
    $dto = $normalizer->normalize($competencyEntry, $barsArray);

    expect($dto->code)->toBe('PRS')
        ->and($dto->name)->toBe('Problem Solving')
        ->and($dto->definition)->toBe('Gather and analyze...')
        ->and($dto->type)->toBe('standard')
        ->and($dto->indicators)->toHaveCount(2);

    $firstIndicator = $dto->indicators[0];
    expect($firstIndicator)->toBeInstanceOf(IndicatorDTO::class)
        ->and($firstIndicator->text)->toBe('Recognizes symptoms that indicate problems.')
        ->and($firstIndicator->anchor5)->toBe('Anchor 5 text')
        ->and($firstIndicator->anchor3)->toBe('Anchor 3 text')
        ->and($firstIndicator->anchor1)->toBe('Anchor 1 text')
        ->and($firstIndicator->position)->toBe(0);

    $secondIndicator = $dto->indicators[1];
    expect($secondIndicator->position)->toBe(1);
});

test('normalizer handles unified shape (competency entry WITH embedded bars)', function (): void {
    $unifiedEntry = [
        'code' => 'PRS',
        'name' => 'Problem Solving',
        'definition' => 'Gather and analyze...',
        'type' => 'standard',
        'bars' => [ // unified shape: bars embedded in competency entry
            [
                'indicator' => 'Recognizes symptoms that indicate problems.',
                'scale' => [
                    '5' => 'Anchor 5 text',
                    '3' => 'Anchor 3 text',
                    '1' => 'Anchor 1 text',
                ],
            ],
        ],
    ];

    $normalizer = new CompetencyNormalizer;
    // Unified shape: pass null for $barsArray — normalizer detects 'bars' key in competencyEntry
    $dto = $normalizer->normalize($unifiedEntry, null);

    expect($dto->code)->toBe('PRS')
        ->and($dto->name)->toBe('Problem Solving')
        ->and($dto->indicators)->toHaveCount(1);

    expect($dto->indicators[0]->text)->toBe('Recognizes symptoms that indicate problems.')
        ->and($dto->indicators[0]->anchor5)->toBe('Anchor 5 text')
        ->and($dto->indicators[0]->position)->toBe(0);
});

test('normalizer produces same DTO from split and unified shapes for identical data', function (): void {
    $competencyEntry = [
        'code' => 'COM',
        'name' => 'Communication',
        'definition' => 'Communicate effectively...',
        'type' => 'standard',
    ];

    $barsArray = [
        [
            'indicator' => 'Indicator 1',
            'scale' => ['5' => 'A5', '3' => 'A3', '1' => 'A1'],
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
