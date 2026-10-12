<?php

declare(strict_types=1);

use App\Jobs\PublicApi\GenerateExportJob;

function csvCellOf(mixed $value): string
{
    return (new ReflectionMethod(GenerateExportJob::class, 'csvCell'))->invoke(null, $value);
}

test('an empty JSON object renders as {} in a CSV cell, never as an empty cell', function (): void {
    expect(csvCellOf(new stdClass))->toBe('{}');
});

test('a JSON object of string values renders as a JSON object in a CSV cell', function (): void {
    expect(csvCellOf((object) ['ats_application_id' => 'A-4471']))->toBe('{"ats_application_id":"A-4471"}');
});

test('an object that is not a JSON object renders as an empty cell and never throws', function (): void {
    expect(csvCellOf(new DateTimeImmutable('2026-10-13')))->toBe('');
});
