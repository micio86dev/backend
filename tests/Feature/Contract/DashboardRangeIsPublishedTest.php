<?php

declare(strict_types=1);

/**
 * The period filter must exist in the CONTRACT, not only in the API and the
 * browser.
 *
 * `from`/`to` were validated inside `DashboardDateRange::fromRequest()`, a
 * static helper. Scramble reads a controller method's own `validate()` call and
 * its FormRequest — not a helper it calls — so the export published
 * `parameters: null` for both dashboard endpoints and `types/api.ts` emitted
 * `query?: never`. The API honoured the range the whole time. Nothing between
 * the two repos recorded that it did.
 *
 * CI's fresh-export diff cannot catch this: it compares an export to itself and
 * is green whether or not the parameters are there (the reasoning is spelled
 * out in ResourceMatchesSpecTest). The backoffice E2E cannot catch it either —
 * it asserts the BROWSER emits `?from=…&to=…` against a mocked response, which
 * passes whether the server reads them or discards them.
 *
 * So this reads the committed spec directly. Move the rules back into the
 * helper and it goes red.
 */

/**
 * @return array<int, string>
 */
function dashboardQueryParams(string $path): array
{
    /** @var array{paths: array<string, array<string, array{parameters?: array<int, array{name: string, in: string}>}>>} $spec */
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, 512, JSON_THROW_ON_ERROR);

    $parameters = $spec['paths'][$path]['get']['parameters'] ?? [];

    return collect($parameters)
        ->where('in', 'query')
        ->pluck('name')
        ->all();
}

it('publishes the from/to range on both dashboard endpoints', function (string $path) {
    // BOTH, and that is the point: the tiles and the feed take the same range
    // so they cannot describe different periods. A spec that documents it on
    // one endpoint and not the other invites exactly that.
    expect(dashboardQueryParams($path))->toContain('from')->toContain('to');
})->with(['/dashboard/metrics', '/dashboard/activity']);
