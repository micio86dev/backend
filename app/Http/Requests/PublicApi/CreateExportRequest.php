<?php

declare(strict_types=1);

namespace App\Http\Requests\PublicApi;

use App\Enums\ExportFormat;
use App\Enums\ExportScope;
use App\Rules\PublicApi\Iso8601DateTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /v1/exports` request validation (public-api step 8, SPEC.md §3.3
 * "Exports", `openapi.yaml`'s `CreateExportRequest` schema).
 *
 * FORMAT only — the "one active export per organization" concurrency gate
 * needs the resolved `Organization` and lives in
 * `App\Http\Controllers\PublicApi\ExportController::store()`, mirroring
 * `CreateInterviewRequest`'s own division of labour.
 *
 * `authorize()` returns `true` unconditionally — scope enforcement
 * (`scope:exports:write`) is `App\Http\Middleware\PublicApi\RequireScope`'s
 * job, applied per-route in `routes/api.php`.
 */
final class CreateExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', Rule::in(ExportScope::values())],
            'format' => ['required', 'string', Rule::in(ExportFormat::values())],
            'from' => ['sometimes', 'nullable', 'string', new Iso8601DateTime],
            'to' => ['sometimes', 'nullable', 'string', new Iso8601DateTime],
            'include_transcripts' => ['sometimes', 'boolean'],
            'include_scoring' => ['sometimes', 'boolean'],
            'include_audio' => ['sometimes', 'boolean'],
        ];
    }
}
