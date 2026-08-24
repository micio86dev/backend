<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Enums\NotificationSubjectType;
use App\Enums\NotificationSuppressionReason;
use App\Enums\NotificationType;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Notifications\ScoringFailedNotification;
use App\Notifications\WebhookDeliveryDeadNotification;
use App\Support\Demo\DemoMarker;
use App\Support\Notifications\OperatorRecipientResolver;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The ONE queue boundary in C12 (D6).
 *
 * Constructor takes SCALARS only — no models. A serialized model is a snapshot
 * that can be stale or, worse, carry an organization id the job would then
 * trust without verifying. The subject is re-loaded from the database here, and
 * the organization is derived from THAT row.
 *
 * Order of operations is deliberate and each step is load-bearing:
 *
 *   1. Load the subject OUTSIDE any tenant context, with global scopes removed
 *      — the ambient org is exactly what must not be trusted.
 *   2. A missing subject logs and returns. An audit trail outliving its subject
 *      is by design (D3), so this is a normal outcome, not an error.
 *   3. Everything else happens inside exactly ONE TenantContextScope::runFor(),
 *      which restores the previous (orgId, bypass, teamId) on the way out.
 *   4. Write the dedupe row BEFORE sending. A crash between INSERT and send
 *      loses one email; the inverse loses idempotency and double-sends.
 *
 * The source contains the literal `TenantContextScope::`, so
 * QueuedJobTenantContextArchTest passes on its own merits with no allowlist
 * entry.
 */
final class SendOperatorNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly NotificationType $type,
        public readonly NotificationSubjectType $subjectType,
        public readonly int $subjectId,
    ) {}

    public function tries(): int
    {
        return (int) config('notifications.dispatch.tries');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        /** @var array<int, int> $backoff */
        $backoff = config('notifications.dispatch.backoff_seconds');

        return $backoff;
    }

    public function timeout(): int
    {
        // Declared explicitly rather than inherited: QueuedJobRetryOwnershipArchTest
        // requires every queued job to own its retry contract, because a job
        // that declares nothing silently takes whatever the worker was started
        // with.
        return 60;
    }

    public function handle(OperatorRecipientResolver $resolver): void
    {
        $subject = $this->loadSubject();

        if ($subject === null) {
            // Not an error. The row is gone; the notification is moot.
            Log::info('notification.subject.missing', [
                'notification_type' => $this->type->value,
                'subject_type' => $this->subjectType->value,
                'subject_id' => $this->subjectId,
            ]);

            return;
        }

        /** @var int $organizationId */
        $organizationId = $subject->getAttribute('organization_id');

        TenantContextScope::runFor($organizationId, function () use ($organizationId, $resolver, $subject): void {
            $log = $this->recordAttempt($organizationId);

            if ($log === null) {
                // A terminal row already exists — `sent` or `suppressed`. The
                // DB arbitrated; nothing to do.
                return;
            }

            // Checked FIRST, ahead of recipients and the storm window, because
            // it is the root fact rather than a downstream symptom: a demo
            // project with no operator is suppressed for being demo data, not
            // for a recipient gap the organization does not actually have.
            //
            // Demo webhooks target an RFC 2606 reserved domain that cannot
            // resolve, so they dead-letter by design — the fixture exists to
            // show a `dead` row on the dashboard. Alerting on that trains
            // operators to ignore the channel, and a real dead webhook then
            // goes unread.
            //
            // The row is still written. Suppressed, not erased.
            if ($this->belongsToDemoProject($subject)) {
                $log->forceFill([
                    'status' => NotificationStatus::Suppressed,
                    'suppression_reason' => NotificationSuppressionReason::DemoProject,
                ])->save();

                return;
            }

            $recipients = $resolver->forOrganization($organizationId);

            if ($recipients->isEmpty()) {
                // A recorded OUTCOME, not a crash. An alerting job that throws
                // because nobody is listening helps nobody, and the row is what
                // makes the gap visible on the dashboard.
                $log->forceFill([
                    'status' => NotificationStatus::Suppressed,
                    'suppression_reason' => NotificationSuppressionReason::NoRecipients,
                ])->save();

                return;
            }

            if ($this->withinSuppressionWindow($organizationId)) {
                $log->forceFill([
                    'status' => NotificationStatus::Suppressed,
                    'suppression_reason' => NotificationSuppressionReason::Window,
                ])->save();

                return;
            }

            $this->send($log, $recipients, $organizationId);
        });
    }

    /**
     * Load the subject with global scopes removed, OUTSIDE any tenant context.
     *
     * `withoutGlobalScopes()` is required, not defensive: the tenant scope
     * would filter by an ambient org that is null here by design — this runs
     * after Queue::before reset the resolver — and the whole point is to derive
     * the org FROM this row rather than to filter by one we already assumed.
     */
    /**
     * Whether the notification's subject hangs off a project written by
     * `beai:demo-seed`.
     *
     * Both subject types carry `project_id`, so one lookup serves the webhook
     * and the scoring path alike — covering only the first would let the noise
     * return through the second.
     *
     * DemoMarker is the single source of truth the seed and teardown commands
     * already share, so "what counts as demo data" cannot drift between them
     * and this. It is a PREFIX check: a client slug that merely contains
     * `beai-demo-` mid-string is not demo data and must keep alerting — nobody
     * gets to silence their own operational alerts by choosing a slug.
     */
    private function belongsToDemoProject(Model $subject): bool
    {
        $projectId = $subject->getAttribute('project_id');

        if ($projectId === null) {
            return false;
        }

        $project = Project::withoutGlobalScopes()->find((int) $projectId);

        return DemoMarker::isDemoSlug($project?->slug);
    }

    private function loadSubject(): ?Model
    {
        return match ($this->subjectType) {
            NotificationSubjectType::WebhookDelivery => WebhookDelivery::withoutGlobalScopes()->find($this->subjectId),
            NotificationSubjectType::Participant => Participant::withoutGlobalScopes()->find($this->subjectId),
        };
    }

    /**
     * Write the dedupe row, or decide based on the one that already exists.
     *
     * @return NotificationLog|null null when an existing TERMINAL row means
     *                              there is nothing left to do
     */
    private function recordAttempt(int $organizationId): ?NotificationLog
    {
        try {
            // The inner DB::transaction() is not decoration. A caught 23505
            // rolls back to a SAVEPOINT rather than aborting the enclosing
            // transaction at the Postgres level — without it, a caller (or a
            // RefreshDatabase-wrapped test) already inside a transaction finds
            // EVERY subsequent statement failing with "current transaction is
            // aborted", even though the exception was handled here.
            return DB::transaction(fn (): NotificationLog => NotificationLog::create([
                'organization_id' => $organizationId,
                'notification_type' => $this->type,
                'subject_type' => $this->subjectType,
                'subject_id' => $this->subjectId,
                'status' => NotificationStatus::Pending,
            ]));
        } catch (UniqueConstraintViolationException) {
            /** @var NotificationLog|null $existing */
            $existing = NotificationLog::where('notification_type', $this->type)
                ->where('subject_type', $this->subjectType)
                ->where('subject_id', $this->subjectId)
                ->first();

            if ($existing === null) {
                return null;
            }

            // The EXISTING row's status decides. sent/suppressed are terminal;
            // pending/failed mean an unfinished send, so continue with it.
            return in_array($existing->status, [NotificationStatus::Sent, NotificationStatus::Suppressed], true)
                ? null
                : $existing;
        }
    }

    /**
     * Has a notification of this type already gone out for this org, recently?
     *
     * The organization filter comes from the TenantScoped global scope here —
     * legitimate, unlike in the recipient resolver, because NotificationLog IS
     * a TenantModel and this runs inside runFor().
     */
    private function withinSuppressionWindow(int $organizationId): bool
    {
        return NotificationLog::query()
            ->where('notification_type', $this->type)
            ->where('status', NotificationStatus::Sent)
            ->where('sent_at', '>=', now()->subSeconds($this->windowSeconds()))
            ->exists();
    }

    private function windowSeconds(): int
    {
        /** @var array<string, int> $byType */
        $byType = config('notifications.suppression.window_seconds_by_type', []);

        return (int) ($byType[$this->type->value] ?? config('notifications.suppression.window_seconds'));
    }

    /**
     * Send, grouped by resolved locale.
     *
     * One send per locale group, NOT one send for the whole collection: a
     * single collection-wide send applies one language to everybody, which for
     * a mixed it/en recipient set means half the operators get the wrong one.
     *
     * @param  Collection<int, User>  $recipients
     */
    private function send(NotificationLog $log, Collection $recipients, int $organizationId): void
    {
        $carried = $this->suppressedCarriedCount();
        // Read the name straight off the id we already derived, rather than
        // traversing $log->organization. The relation accessor is typed
        // nullable, and the alternatives — a nullsafe chain or a null coalesce —
        // both encode a "maybe missing" case that a NOT NULL cascading FK makes
        // impossible. A single scalar lookup states what is actually true.
        $organizationName = (string) Organization::query()->whereKey($organizationId)->value('name');

        try {
            foreach ($recipients->groupBy(fn (User $u): string => $this->localeFor($u)) as $locale => $group) {
                $notification = $this->makeNotification($carried, $organizationName);
                Notification::sendNow($group, $notification->locale((string) $locale));
            }

            $log->forceFill([
                'status' => NotificationStatus::Sent,
                'sent_at' => now(),
                'recipient_count' => $recipients->count(),
                'suppressed_carried_count' => $carried,
            ])->save();
        } catch (Throwable $e) {
            $log->forceFill([
                'status' => NotificationStatus::Failed,
                'last_error' => $this->truncate($e->getMessage()),
            ])->save();

            // Rethrown on purpose: the row records the attempt, the QUEUE owns
            // the retry. Swallowing here would leave a `failed` row that never
            // gets another chance.
            throw $e;
        }
    }

    private function makeNotification(int $carried, string $organizationName): WebhookDeliveryDeadNotification|ScoringFailedNotification
    {
        $windowMinutes = (int) round($this->windowSeconds() / 60);

        return match ($this->type) {
            NotificationType::WebhookDeliveryDead => new WebhookDeliveryDeadNotification(
                (int) config('webhooks.delivery.max_attempts'),
                $organizationName,
                $carried,
                $windowMinutes,
            ),
            NotificationType::ScoringFailed => new ScoringFailedNotification(
                $organizationName,
                $carried,
                $windowMinutes,
            ),
        };
    }

    /**
     * Suppressed rows for this (org, type) since the last `sent` one.
     *
     * Carried into the email that finally goes out, so a suppression window
     * never silently swallows scale: the operator sees "1 new failure — 46
     * further failures suppressed", and can tell one broken endpoint from a
     * total outage.
     */
    private function suppressedCarriedCount(): int
    {
        $lastSentAt = NotificationLog::query()
            ->where('notification_type', $this->type)
            ->where('status', NotificationStatus::Sent)
            ->max('sent_at');

        return NotificationLog::query()
            ->where('notification_type', $this->type)
            ->where('status', NotificationStatus::Suppressed)
            // `>=`, not `>`. A row suppressed BECAUSE of that send can share
            // its timestamp to the second, and a strict comparison drops
            // exactly the rows this count exists to report — silently, and
            // always in the direction of under-reporting the storm.
            ->when($lastSentAt !== null, fn ($q) => $q->where('created_at', '>=', $lastSentAt))
            ->count();
    }

    private function localeFor(User $user): string
    {
        /** @var list<string> $supported */
        $supported = config('app.supported_locales', ['en']);
        $locale = $user->getAttribute('locale');

        // No HTTP request exists inside a queued job, so the locale is read off
        // the model — the same shape ScoreEvaluationJob uses for project
        // language. An unsupported or null value falls back rather than
        // producing an email full of raw translation keys.
        return is_string($locale) && in_array($locale, $supported, true)
            ? $locale
            : (string) config('app.fallback_locale');
    }

    private function truncate(string $message): string
    {
        $max = (int) config('notifications.dispatch.last_error_max_chars');

        return mb_substr($message, 0, $max);
    }

    /**
     * Retries exhausted. Force the row to `failed` so the dashboard can show
     * "we tried to alert you and could not" — the terminal signal, and
     * deliberately NOT another notification (self-referential, same broken
     * channel, one outage becomes an amplification storm).
     */
    public function failed(Throwable $e): void
    {
        $subject = $this->loadSubject();

        if ($subject === null) {
            return;
        }

        /** @var int $organizationId */
        $organizationId = $subject->getAttribute('organization_id');

        TenantContextScope::runFor($organizationId, function () use ($e): void {
            NotificationLog::query()
                ->where('notification_type', $this->type)
                ->where('subject_type', $this->subjectType)
                ->where('subject_id', $this->subjectId)
                ->update([
                    'status' => NotificationStatus::Failed,
                    'last_error' => $this->truncate($e->getMessage()),
                ]);
        });

        Log::error('notification.dispatch.failed', [
            'notification_type' => $this->type->value,
            'subject_type' => $this->subjectType->value,
            'subject_id' => $this->subjectId,
        ]);
    }
}
