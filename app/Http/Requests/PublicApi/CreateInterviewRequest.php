<?php

declare(strict_types=1);

namespace App\Http\Requests\PublicApi;

use App\Rules\NotPlaceholderEmail;
use App\Rules\PublicApi\Metadata;
use App\Support\Participant\ExternalReference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * The body of `POST /v1/interviews`: the candidate to enrol, the project to
 * enrol them on, and optional metadata and redirect URL.
 */
final class CreateInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `candidate.external_id` / `candidate.source` are the optional external
     * reference (candidate-external-reference): the SAME rule set every other
     * write surface spreads, prefixed for the nested `candidate` object, so a
     * body is refused identically wherever it arrives. A refusal is the
     * ordinary `422 validation_failed` whose `errors[].field` names the nested
     * field. This prose lives here, not in the class docblock, because Scramble
     * publishes the class docblock as the schema description.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reference = ExternalReference::rules('candidate.');
        $externalId = Arr::pull($reference, 'candidate.external_id');

        return [
            'project_id' => ['required', 'string'],
            'candidate' => ['required', 'array'],
            'candidate.candidate_ref' => ['required', 'string', 'max:255'],
            // Valid email address, at most 255 characters.
            'candidate.email' => ['required', 'email', 'max:255', new NotPlaceholderEmail],
            'candidate.display_name' => ['required', 'string', 'max:255'],
            // ISO 639-1 language code: two lowercase letters, for example `it`.
            'candidate.language' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z]{2}$/'],
            // The calling system's own integer id for the candidate: an integer from 1 to 9007199254740991.
            'candidate.external_id' => $externalId,
            ...$reference,
            'metadata' => ['sometimes', 'nullable', new Metadata],
            // Must be an https URL of at most 2048 characters.
            'exit_redirect_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048', 'starts_with:https://'],
        ];
    }
}
