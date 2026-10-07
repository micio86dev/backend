<?php

declare(strict_types=1);

/**
 * `EvaluationResource::with()` — the `meta` envelope is omitted entirely when
 * the resource carries neither scoring nor audit provenance, and present as soon
 * as either side is supplied (the other staying an explicit null).
 */

use App\Http\Resources\Admin\EvaluationResource;
use Illuminate\Http\Request;

test('with() returns no meta key at all when neither scoring nor audit meta is supplied', function (): void {
    $resource = new EvaluationResource(['SLF' => ['score' => 4.0]]);

    expect($resource->with(Request::create('/')))->toBe([])
        ->and($resource->resolve())->toBe(['SLF' => ['score' => 4.0]]);
});

test('with() keeps the missing side as an explicit null once either meta is supplied', function (): void {
    $scoring = ['prompt_version' => 'p1', 'model_version' => 'm1', 'framework_version' => 'v1.0.0'];

    $onlyScoring = (new EvaluationResource([], $scoring))->with(Request::create('/'));

    expect($onlyScoring)->toBe(['meta' => ['scoring' => $scoring, 'audit' => null]]);
});
