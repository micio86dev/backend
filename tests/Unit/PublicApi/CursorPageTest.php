<?php

declare(strict_types=1);

/**
 * `CursorPage::encodeCursor()` unit tests (public-api step 3 review
 * follow-up 11) — a model whose `created_at` or primary key cannot be
 * trusted must never silently produce a cursor pointing at `Carbon::now()`
 * (or an empty id) instead of surfacing the data problem that produced it.
 *
 * `encodeCursor()` is private; reached through reflection, exactly per the
 * review's own suggestion, rather than duplicating `CursorPage::paginate()`'s
 * whole query/Builder setup just to reach this one branch. A tiny anonymous
 * Eloquent model (never queried/saved — only ever constructed directly)
 * stands in for a real one, per the review's own "prefer a small fake
 * Eloquent model over an array schema" guidance.
 */

use App\Support\PublicApi\CursorPage;
use Illuminate\Database\Eloquent\Model;

function ncEncodeCursor(Model $model): string
{
    $method = new ReflectionMethod(CursorPage::class, 'encodeCursor');
    $method->setAccessible(true);

    /** @var string $result */
    $result = $method->invoke(null, $model);

    return $result;
}

function ncFakeModel(): Model
{
    // incrementing=false — Eloquent's own getCasts() auto-injects an
    // `int` cast on the primary key whenever $incrementing is true
    // (HasAttributes::getCasts()), which would silently coerce the
    // non-scalar id assigned below to `(int)` (PHP's own array→int cast:
    // 1 for a non-empty array) BEFORE encodeCursor() ever saw it — masking
    // exactly the case this test exists to prove.
    return new class extends Model
    {
        protected $table = 'api_clients';

        public $timestamps = false;

        public $incrementing = false;

        protected $guarded = [];
    };
}

test('review follow-up 11: a model with a null created_at throws a LogicException naming the model', function (): void {
    $model = ncFakeModel();
    $model->setAttribute('id', 42);

    expect(fn () => ncEncodeCursor($model))
        ->toThrow(LogicException::class, $model::class);
});

test('review follow-up 11: a model whose primary key is neither int nor string throws a LogicException naming the model', function (): void {
    $model = ncFakeModel();
    $model->setAttribute('created_at', now());
    $model->setAttribute('id', ['not', 'a', 'scalar']);

    expect(fn () => ncEncodeCursor($model))
        ->toThrow(LogicException::class, $model::class);
});

test('review follow-up 11: a model with a usable created_at and a scalar id encodes successfully', function (): void {
    $model = ncFakeModel();
    $model->setAttribute('id', 7);
    $model->setAttribute('created_at', now());

    $cursor = ncEncodeCursor($model);

    expect($cursor)->toBeString();
    expect($cursor === '')->toBeFalse();
});
