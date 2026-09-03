<?php

declare(strict_types=1);

namespace App\Support\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The dashboard's date range, parsed ONCE and applied to every query.
 *
 * The tiles and the activity list must describe the same period. Deriving the
 * range separately in each endpoint would eventually let them disagree, and a
 * dashboard whose numbers and list cover different months is worse than one
 * with no filter at all — nothing on the screen says they differ.
 *
 * Filtered on `created_at`: when the thing HAPPENED. `activity` orders by
 * `updated_at` because an operator wants the most recently touched first, but
 * a participant created in March and edited in June belongs to March when you
 * ask what March looked like.
 */
final class DashboardDateRange
{
    private function __construct(
        public readonly ?Carbon $from,
        public readonly ?Carbon $to,
    ) {}

    /**
     * Both ends are optional; absent means "all time", which is exactly the
     * behaviour every caller had before this existed.
     */
    public static function fromRequest(Request $request): self
    {
        $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            // `after_or_equal` rather than `after`: a single-day range is a
            // legitimate question ("what happened on the 3rd"), and refusing
            // it would be an arbitrary restriction dressed as validation.
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
        ]);

        // `filled()` rather than isset + !== null: the validator lets both an
        // absent key and an explicit null through, and both mean "no bound".
        $from = $request->filled('from')
            ? Carbon::parse((string) $request->input('from'))->startOfDay()
            : null;

        // END OF DAY, and this is the bug that makes a month look short.
        // `to=2026-03-31` parses to midnight, so a plain `<=` drops everything
        // that happened during the final day — an operator asking about March
        // would silently lose the 31st.
        $to = $request->filled('to')
            ? Carbon::parse((string) $request->input('to'))->endOfDay()
            : null;

        return new self($from, $to);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, string $column = 'created_at'): Builder
    {
        if ($this->from !== null) {
            $query->where($column, '>=', $this->from);
        }

        if ($this->to !== null) {
            $query->where($column, '<=', $this->to);
        }

        return $query;
    }
}
