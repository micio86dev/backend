<?php

declare(strict_types=1);

/**
 * The advance notice a scheduled candidate receives before their interview
 * link arrives (interview-scheduling, design AD-9).
 *
 * STANDARD AND STATIC, per the 2026-09-01 ruling: multilingual with
 * placeholders, not editable by tenant admins — same discipline as
 * `candidate_invitation.php`.
 *
 * Rendered in the language of the PROJECT (or the participant's own, when
 * set), never the operator's or the queue worker's.
 *
 * Deliberately carries NO link and NO expiry label — see
 * `App\Notifications\CandidateInterviewNoticeNotification`'s own docblock for
 * why this content is not merely a trimmed copy of `candidate_invitation.php`.
 */
return [

    'subject' => 'Your interview for :project is coming up',

    'greeting' => 'Hello :name',

    'intro' => ':organization has scheduled your interview for :project.',

    'what_happens_next' => 'The link to start your interview will arrive in a separate '
        .'email shortly, once your scheduled time is reached — there is nothing for you to '
        .'do right now.',

    'requirements' => 'When it arrives: use a desktop or laptop with Chrome, Edge, Opera '
        .'or Safari. Phones, tablets and Firefox are not supported.',

    'salutation' => 'See you soon',

];
