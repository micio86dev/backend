<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Catalogue\Concerns\ValidatesLocaleMaps;
use App\Rules\NoCompletionPhrase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The body of `PATCH /api/admin/settings` (superadmin only).
 *
 * Every knob is optional, but a body naming none of them is refused: it would
 * be a no-op the caller believes did something.
 */
class UpdatePlatformSettingsRequest extends FormRequest
{
    use ValidatesLocaleMaps;

    /** Roughly 4 minutes of speech: the intro counts against the interview clock. */
    public const INTRO_MAX_CHARACTERS = 3600;

    /** Same budget as the intro: the closing counts against the interview clock too. */
    public const CLOSING_MAX_CHARACTERS = 3600;

    public function authorize(): bool
    {
        return $this->user()?->is_superadmin === true;
    }

    /**
     * `null` clears the intro, so its map is nullable. The intro is spoken before the first question,
     * so it may not contain anything the client reads as the end of the interview.
     *
     * `Rule::array([...])` on the caps is load-bearing: without it a body naming no recognised
     * sub-key left `validated()` empty (Laravel drops the parent of an `array` rule with dotted
     * sub-rules) and reached the setter as a TypeError, a 500 for what should be a 422.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $intro = $this->localeMapRules('interview_intro_phrase', ['en', 'it'], required: false);
        array_unshift($intro['interview_intro_phrase'], 'nullable');
        $intro['interview_intro_phrase.en'][] = 'max:'.self::INTRO_MAX_CHARACTERS;
        $intro['interview_intro_phrase.en'][] = new NoCompletionPhrase;
        $intro['interview_intro_phrase.it'][] = 'max:'.self::INTRO_MAX_CHARACTERS;
        $intro['interview_intro_phrase.it'][] = new NoCompletionPhrase;

        $closing = $this->localeMapRules('interview_closing_phrase', ['en', 'it'], required: false);
        array_unshift($closing['interview_closing_phrase'], 'nullable');

        foreach (['en', 'it'] as $locale) {
            $closing["interview_closing_phrase.{$locale}"][] = 'max:'.self::CLOSING_MAX_CHARACTERS;
            // Spoken before the final phrase, so only an END phrase (which would close a competency early) is refused.
            $closing["interview_closing_phrase.{$locale}"][] = new NoCompletionPhrase(includeFinal: false);
        }

        return [
            'max_questions_per_competency' => ['sometimes', 'required', 'array', Rule::array(['standard', 'potential'])],
            'max_questions_per_competency.standard' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'max_questions_per_competency.potential' => ['sometimes', 'integer', 'min:1', 'max:10'],
            /**
             * The spoken introduction per locale. A map REPLACES the stored one and `null` clears it;
             * `en` is required, `it` optional, each at most 3600 characters.
             *
             * @var array{en: string, it?: string}|null
             */
            'interview_intro_phrase' => $intro['interview_intro_phrase'],
            'interview_intro_phrase.en' => $intro['interview_intro_phrase.en'],
            'interview_intro_phrase.it' => $intro['interview_intro_phrase.it'],
            /**
             * The closing greeting per locale, spoken on the LAST competency right before the final phrase.
             * A map REPLACES the stored one and `null` clears it; `en` is required, `it` optional, each at
             * most 3600 characters and free of the end phrase.
             *
             * @var array{en: string, it?: string}|null
             */
            'interview_closing_phrase' => $closing['interview_closing_phrase'],
            'interview_closing_phrase.en' => $closing['interview_closing_phrase.en'],
            'interview_closing_phrase.it' => $closing['interview_closing_phrase.it'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['max_questions_per_competency', 'interview_intro_phrase', 'interview_closing_phrase'])) {
                    $validator->errors()->add('max_questions_per_competency', 'max_questions_per_competency_required');
                }
            },
        ];
    }
}
