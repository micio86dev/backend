<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The advance notice a scheduled candidate receives before their interview
 * link arrives (interview-scheduling, design AD-9).
 *
 * NO LINK, DELIBERATELY. The candidate's actual entry link is minted and sent
 * separately — by the SAME, unchanged `CandidateInvitationNotification` this
 * class does not duplicate — at the moment
 * `App\Console\Commands\DispatchScheduledInterviewInvitations`'s start-due
 * branch fires, never here. Sending a link this early would let it sit
 * unused for up to `App\Support\Scheduling\ScheduledInterviewWindow::NOTICE_LEAD_MINUTES`
 * minutes against its fixed 30-minute TTL
 * (`App\Support\Jwt\CandidateTokenFactory::SSO_LINK_TTL_MINUTES`), which
 * could expire before the candidate ever opens it (design AD-6).
 *
 * A PURE RENDERER, exactly like `CandidateInvitationNotification` — MUST NOT
 * implement `ShouldQueue`: `tests/Arch/Tenancy/NotificationNeverQueuedArchTest.php`
 * forbids it for every class under `app/Notifications/`. The queue boundary
 * is `App\Jobs\SendScheduledInterviewNoticeJob`.
 *
 * ADDRESSED, NOT BROADCAST — same reasoning as `CandidateInvitationNotification`:
 * sent with `Notification::route()` to one address rather than to a
 * notifiable model, because a Participant is not a user of this system.
 */
final class CandidateInterviewNoticeNotification extends Notification
{
    public function __construct(
        private readonly string $displayName,
        private readonly string $organizationName,
        private readonly string $projectName,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('candidate_interview_notice.subject', ['project' => $this->projectName]))
            ->greeting(__('candidate_interview_notice.greeting', ['name' => $this->displayName]))
            ->line(__('candidate_interview_notice.intro', [
                'organization' => $this->organizationName,
                'project' => $this->projectName,
            ]))
            ->line(__('candidate_interview_notice.what_happens_next'))
            ->line(__('candidate_interview_notice.requirements'))
            ->salutation(__('candidate_interview_notice.salutation'));
    }
}
