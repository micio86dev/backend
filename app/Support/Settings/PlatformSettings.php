<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Models\PlatformSetting;

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
                // CLAMPED on the way out, where every read passes.
                //
                // The floor of 1 is the one invariant this class documents as
                // load-bearing — `updateSettings()` argues at length that a cap
                // of 0 makes every save fail with a message about a maximum —
                // and it was enforced only at the HTTP boundary. The setter is
                // reachable from tests, from the factory, and from a
                // hand-edited row, all of which could store 0 or -5 and pass
                // `is_numeric`. Guarding the read means no writer can produce
                // the state the controller warns about.
                $normalised[$type] = max(1, (int) $max);
            }
        }

        return array_merge(self::MAX_QUESTIONS_DEFAULT, $normalised);
    }

    /**
     * Write the caps named in `$caps`, leaving the rest alone.
     *
     * @param  array<string, int>  $caps
     * @return array<string, int> The full map as it now stands.
     */
    public function setMaxQuestionsPerCompetency(array $caps): array
    {
        // Cast on the way IN, at the single choke point both the write and the
        // returned payload pass through.
        //
        // `$request->validate()` validates without casting, and Laravel's
        // `integer` rule accepts the numeric string "3". On a form-encoded
        // PATCH that string was written into the JSON column and echoed on the
        // wire, while `@scramble-return` on both endpoints declares `int` and
        // the GET path normalises. Two endpoints, one declared shape, two wire
        // types — and the stored type decided by the caller's content-type.
        // This repo already calls a spec where integers come back as strings
        // WRONG rather than merely different, because both Nuxt apps generate
        // their client from it.
        $normalised = [];

        foreach ($caps as $type => $max) {
            // CLAMPED here too, not only on the read.
            //
            // The read path clamped and this one did not, so a PATCH of 0
            // echoed `{"standard": 0}` on the wire while the very next GET
            // answered 1. One setting, two endpoints, two different answers —
            // and the client believes whichever it asked last.
            $normalised[$type] = max(1, (int) $max);
        }

        $merged = array_merge($this->maxQuestionsPerCompetencyMap(), $normalised);

        // updateOrCreate on the key, so there is exactly one row per setting
        // however many times it is written. The key IS the primary key, so
        // this cannot produce a second row to disagree with the first.
        PlatformSetting::updateOrCreate(
            ['key' => self::MAX_QUESTIONS_KEY],
            ['value' => $merged],
        );

        return $merged;
    }
}
