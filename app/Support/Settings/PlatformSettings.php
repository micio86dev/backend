<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\DB;

/**
 * Typed reads and writes for the platform's own settings.
 *
 * Every caller goes through here rather than touching `PlatformSetting`
 * directly, because a raw row read answers `null` for a setting nobody has
 * ever written — and `null` is not what the product does in that state. It
 * does what it has always done, which is the DEFAULT below.
 *
 * THE DEFAULTS ARE THE OLD CONSTANT, unchanged. This knob used to be
 * `private const MAX_PER_COMPETENCY = ['standard' => 1, 'potential' => 4]`
 * inside `StoreProjectQuestionRequest`. A settings store whose defaults differ
 * from the behaviour it replaces is a silent change to the product's rules
 * dressed up as a refactor, so: same numbers, different owner.
 *
 * WHY THE CAPS DIFFER. A `standard` interview may have ONE predefined question
 * per competency because the adaptivity is the product — the interviewer is
 * supposed to follow the candidate, not read a script. A `potential`
 * assessment is four fixed questions by design (SA-08), so its cap matches.
 *
 * NOT CACHED. One indexed primary-key lookup per validated question save is
 * not a cost worth a cache-invalidation bug — and a stale cap is the kind of
 * defect that surfaces as "the setting I just changed did nothing".
 */
final class PlatformSettings
{
    public const MAX_QUESTIONS_KEY = 'max_questions_per_competency';

    public const INTERVIEW_INTRO_KEY = 'interview_intro_phrase';

    /**
     * The locales an intro can be authored in. `en` is the fallback.
     */
    private const INTRO_LOCALES = ['en', 'it'];

    /**
     * @var array<string, int>
     */
    private const MAX_QUESTIONS_DEFAULT = ['standard' => 1, 'potential' => 4];

    /**
     * The cap for one assessment type.
     *
     * An unknown type falls back to the most restrictive default rather than
     * to "unlimited": `assessment_type` is a closed union today, and the safe
     * answer to a value this class has never heard of is the conservative one.
     */
    public function maxQuestionsPerCompetency(string $assessmentType): int
    {
        $configured = $this->maxQuestionsPerCompetencyMap();

        return $configured[$assessmentType] ?? min(self::MAX_QUESTIONS_DEFAULT);
    }

    /**
     * Every cap, defaults merged under whatever has been configured.
     *
     * MERGED rather than replaced, so a PATCH that names only `standard`
     * leaves `potential` at its default instead of erasing it — a partial
     * write must not be a silent reset of everything it did not mention.
     *
     * Stored values are floored at 1: a cap of 0 would make every save fail.
     *
     * @return array<string, int>
     */
    public function maxQuestionsPerCompetencyMap(): array
    {
        $stored = PlatformSetting::query()->find(self::MAX_QUESTIONS_KEY)?->value;

        if (! is_array($stored)) {
            return self::MAX_QUESTIONS_DEFAULT;
        }

        $normalised = [];

        foreach ($stored as $type => $max) {
            if (is_string($type) && is_numeric($max)) {
                $normalised[$type] = max(1, (int) $max);
            }
        }

        return array_merge(self::MAX_QUESTIONS_DEFAULT, $normalised);
    }

    /**
     * Write the caps named in `$caps`, leaving the rest alone.
     *
     * Values are cast to int (`integer` validation accepts "3" from form-encoded
     * input) and floored at 1, like the read, so PATCH and GET never disagree.
     * The read-merge-write runs under a row lock, the row inserted first so
     * FOR UPDATE always has something to lock: concurrent PATCHes serialise and
     * the second merges onto the first's result. The write goes through the
     * model so the `array` cast encodes the JSON column.
     *
     * @param  array<string, int>  $caps
     * @return array<string, int> The full map as it now stands.
     */
    public function setMaxQuestionsPerCompetency(array $caps): array
    {
        $normalised = [];

        foreach ($caps as $type => $max) {
            $normalised[$type] = max(1, (int) $max);
        }

        $merged = DB::transaction(function () use ($normalised): array {
            DB::table('platform_settings')->insertOrIgnore([
                'key' => self::MAX_QUESTIONS_KEY,
                'value' => json_encode(self::MAX_QUESTIONS_DEFAULT, JSON_THROW_ON_ERROR),
            ]);

            $row = PlatformSetting::query()->lockForUpdate()->findOrFail(self::MAX_QUESTIONS_KEY);

            $merged = array_merge($this->maxQuestionsPerCompetencyMap(), $normalised);

            $row->update(['value' => $merged]);

            return $merged;
        });

        return $merged;
    }

    /**
     * The intro the avatar speaks before the first question, per locale.
     *
     * Only non-blank strings for a supported locale survive the read, so a
     * hand-edited or malformed row degrades to "no intro" instead of reaching
     * the avatar as junk. Unset is `[]`: the whole setting is optional.
     *
     * @return array<string, string>
     */
    public function interviewIntroPhraseMap(): array
    {
        $stored = PlatformSetting::query()->find(self::INTERVIEW_INTRO_KEY)?->value;

        if (! is_array($stored)) {
            return [];
        }

        $map = [];

        foreach (self::INTRO_LOCALES as $locale) {
            $text = $stored[$locale] ?? null;

            if (is_string($text) && trim($text) !== '') {
                $map[$locale] = $text;
            }
        }

        return $map;
    }

    /**
     * The intro for a project language: that language, else `en`, else none.
     */
    public function interviewIntroPhrase(string $locale): ?string
    {
        $map = $this->interviewIntroPhraseMap();

        return $map[$locale] ?? $map['en'] ?? null;
    }

    /**
     * REPLACE the stored intro map; null or `[]` clears it.
     *
     * Unlike the caps, a partial write must not merge: the superadmin sends the
     * whole text they want spoken, and a stale `it` left behind by an
     * en-only save would be spoken to Italian candidates unseen.
     *
     * @param  array<string, string>|null  $map
     * @return array<string, string> The map as it now stands.
     */
    public function setInterviewIntroPhrase(?array $map): array
    {
        if ($map === null || $map === []) {
            PlatformSetting::query()->whereKey(self::INTERVIEW_INTRO_KEY)->delete();

            return [];
        }

        PlatformSetting::updateOrCreate(
            ['key' => self::INTERVIEW_INTRO_KEY],
            ['value' => $map],
        );

        return $this->interviewIntroPhraseMap();
    }
}
