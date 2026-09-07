<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DashboardRangeRequest — the `from`/`to` period filter shared by
 * `GET /api/dashboard/metrics` and `GET /api/dashboard/activity` (C11, D2).
 *
 * WHY A FORM REQUEST AND NOT `$request->validate()` IN THE HELPER
 * ---------------------------------------------------------------
 * The rules used to live inside `DashboardDateRange::fromRequest()`. They
 * validated correctly, and Scramble could not see them: it reads a controller
 * method's own `validate()` call and its FormRequest, not a static helper the
 * method happens to call. So `openapi.json` published `parameters: null` for
 * both endpoints, `types/api.ts` emitted `query?: never`, and the ENTIRE period
 * filter existed only in the browser's request and the API's parser — nowhere
 * in the contract that sits between them.
 *
 * That is worse than an undocumented feature. A consumer generating a client
 * from the spec had no way to learn the parameters exist, and nothing in either
 * repo could tell you whether the API honoured them or discarded them — the
 * dashboard's own E2E asserts the browser SENDS `?from=…&to=…` against a mocked
 * response, which is green either way.
 *
 * The rules therefore live HERE, once. `DashboardDateRange` parses what this
 * has already validated.
 *
 * No `authorize()` override: neither endpoint's data is reachable without the
 * `viewAny` gate that `AdminParticipantReader::listQuery()` applies, and a
 * second gate here would be a second place to keep in step.
 */
class DashboardRangeRequest extends FormRequest
{
    /**
     * Both ends are optional and both are nullable — absent means "all time",
     * which is the behaviour every caller had before the filter existed.
     *
     * `after_or_equal` rather than `after`: a single-day range is a legitimate
     * question ("what happened on the 3rd"), and refusing it would be an
     * arbitrary restriction dressed as validation.
     *
     * The comments ON the rules are not notes to the next maintainer. Scramble
     * scrapes whatever sits directly above a rule and publishes it as that
     * parameter's `description` in `openapi.json`, where it becomes JSDoc in
     * three generated clients — so an argument written for a code review shipped
     * as consumer documentation, on `to` alone, leaving the contract reading as
     * if only one end had semantics worth explaining. Rationale goes HERE;
     * anything on a rule line is written for the consumer.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Inclusive lower bound (`YYYY-MM-DD`). Omit for no lower bound.
            'from' => ['sometimes', 'nullable', 'date'],
            // Inclusive upper bound (`YYYY-MM-DD`), covering the whole of that
            // day. Must be on or after `from`. Omit for no upper bound.
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
