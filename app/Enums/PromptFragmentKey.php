<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The 31 prose fragments `SystemPromptComposer` renders into the conversation
 * system prompt (db-driven-conversation-prompts, design N-1).
 *
 * A fragment is a leaf of prose. Branch selection, the minimum clamp, line
 * joins, the `N. question` numbering and the coverage line format are NOT
 * fragments and stay in code.
 *
 * Placeholders use the `{{token}}` delimiter. {@see requiredTokens()} lists the
 * bare token names a key's template must contain; that set is also the whole of
 * what the key may contain — the contract allows no other token.
 *
 * The composer reads these keys through a PromptTemplateSet; only `label.override` (reserved for the
 * per-competency override slice) is not read yet.
 */
enum PromptFragmentKey: string
{
    // Frame
    case Header = 'header';

    // Labels. `label.override` is reserved until the override section ships.
    case LabelOpening = 'label.opening';
    case LabelCoverage = 'label.coverage';
    case LabelOverride = 'label.override';
    case LabelStar = 'label.star';
    case LabelFollowUp = 'label.follow_up';
    case LabelNudge = 'label.nudge';
    case LabelPrimary = 'label.primary';
    case LabelAdvance = 'label.advance';

    // Bodies
    case Star = 'star';
    case Budget = 'budget';
    case Nudge = 'nudge';

    // Opening paragraph
    case OpeningResumedNotice = 'opening.resumed_notice';
    case OpeningFallback = 'opening.fallback';
    case OpeningQuoted = 'opening.quoted';
    case OpeningSpokenReaskAll = 'opening.spoken_reask_all';
    case OpeningSpokenResumed = 'opening.spoken_resumed';
    case OpeningSpokenFresh = 'opening.spoken_fresh';
    case OpeningClosing = 'opening.closing';

    // Primary questions
    case PrimaryNone = 'primary.none';
    case PrimaryIntro = 'primary.intro';
    case PrimaryAskedBeforeOne = 'primary.asked_before_one';
    case PrimaryAskedBeforeMany = 'primary.asked_before_many';
    case PrimaryProgressAllAsked = 'primary.progress_all_asked';
    case PrimaryProgressLast = 'primary.progress_last';
    case PrimaryProgressNext = 'primary.progress_next';

    // Advance rule
    case AdvanceFloorOne = 'advance.floor_one';
    case AdvanceFloorMany = 'advance.floor_many';
    case AdvanceFloorWithPrimaries = 'advance.floor_with_primaries';
    case AdvanceWithPhrase = 'advance.with_phrase';
    case AdvanceWithoutPhrase = 'advance.without_phrase';

    /**
     * Bare names (no braces) of the tokens a template for this key must contain,
     * and the only tokens it may contain.
     *
     * `advance.with_phrase` requires `advance_phrase`: a template without it
     * never tells the avatar the sentence to speak, which is the defect that
     * killed HeyGen sessions with MAX_DURATION_REACHED.
     *
     * Every token-less case is listed on purpose: no `default` arm, so a new
     * case that is not placed here fails as an unhandled `match` (and in PHPStan).
     *
     * @return list<string>
     */
    public function requiredTokens(): array
    {
        return match ($this) {
            self::Header => ['competency_code'],
            self::Budget => ['budget'],
            self::Nudge => ['nudge_min_chars'],
            self::OpeningQuoted => ['number', 'question'],
            self::OpeningSpokenReaskAll,
            self::OpeningSpokenResumed,
            self::OpeningSpokenFresh => ['quoted'],
            self::PrimaryAskedBeforeMany => ['count'],
            self::PrimaryProgressLast => ['spoken'],
            self::PrimaryProgressNext => ['spoken', 'next'],
            self::AdvanceFloorMany => ['min_questions'],
            self::AdvanceFloorWithPrimaries,
            self::AdvanceWithoutPhrase => ['floor'],
            self::AdvanceWithPhrase => ['floor', 'advance_phrase'],
            self::LabelOpening,
            self::LabelCoverage,
            self::LabelOverride,
            self::LabelStar,
            self::LabelFollowUp,
            self::LabelNudge,
            self::LabelPrimary,
            self::LabelAdvance,
            self::Star,
            self::OpeningResumedNotice,
            self::OpeningFallback,
            self::OpeningClosing,
            self::PrimaryNone,
            self::PrimaryIntro,
            self::PrimaryAskedBeforeOne,
            self::PrimaryProgressAllAsked,
            self::AdvanceFloorOne => [],
        };
    }
}
