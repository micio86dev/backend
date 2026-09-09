<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateProjectQuestionRequest.
 *
 * Only the WORDING is editable. The competency is deliberately absent from
 * these rules: a question written to probe one competency is not a question
 * about another, and "moving" it would silently change what an interview
 * measures. Delete and re-author instead.
 *
 * Extracted from an inline `$request->validate()` in the controller that
 * duplicated `StoreProjectQuestionRequest`'s shape — raising `max:2000` in
 * one would have left the other silently disagreeing.
 *
 * Authorization happens HERE, not in the controller, and it has to. A
 * FormRequest is resolved during method-argument resolution, which Laravel
 * runs BEFORE the controller body — so leaving the tenant lookup downstream
 * let validation overtake it, and a PATCH to another organization's project
 * answered 422 for an invalid body where the file's own doctrine says 404.
 */
class UpdateProjectQuestionRequest extends FormRequest
{
    /**
     * The route parameter is an ID, not a bound model: `SubstituteBindings`
     * runs before `TenantContext`, so binding here would resolve with no
     * organization established. Resolved through the tenant scope instead.
     *
     * `findOrFail`, not `find`: a project belonging to somebody else must be
     * NOT FOUND, which is the doctrine the controller states in its own
     * docblock — a 403 confirms the project exists, and that is an existence
     * oracle across tenants. Returning `false` from `authorize()` would answer
     * 403; throwing here answers 404, and it does so BEFORE validation runs,
     * so a foreign id cannot be told apart by the shape of its body either.
     */
    private function project(): Project
    {
        $id = $this->route('project');

        return Project::findOrFail(is_numeric($id) ? (int) $id : 0);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->project()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'array'],
            'text.en' => ['required', 'string', 'max:2000'],
            'text.it' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Machine codes, one per declared rule, matching
     * `StoreProjectQuestionRequest` exactly. Prose in a response body reaches
     * an operator in the API's language, never theirs — and the two classes
     * declare the SAME `text` rules, so one field failing one rule must not
     * answer differently depending on the HTTP verb.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.required' => 'text_required',
            'text.array' => 'text_invalid',
            'text.en.required' => 'text_en_required',
            'text.en.string' => 'text_en_invalid',
            'text.en.max' => 'text_en_too_long',
            'text.it.string' => 'text_it_invalid',
            'text.it.max' => 'text_it_too_long',
        ];
    }
}
