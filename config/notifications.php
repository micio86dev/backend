<?php

declare(strict_types=1);

/**
 * Notifications & Reminders configuration (C12).
 *
 * Nothing here is hardcoded elsewhere — the `config/webhooks.php` rule. Two
 * values are product assumptions rather than ratified decisions (the recipient
 * role set and the suppression window); they live here precisely so confirming
 * them costs a config change, not a code change.
 *
 * There is deliberately NO `queue` key. See `dispatch` below.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Recipients
    |--------------------------------------------------------------------------
    |
    | Which users receive operator notifications, resolved ONLY through
    | App\Support\Notifications\OperatorRecipientResolver.
    |
    | `roles` are Spatie AUTHORIZATION roles. Do NOT confuse them with BEAI
    | ORGANIZATIONAL roles (ICO/FLL/MLL/BUL/SRX), which are a domain concept
    | with no bearing on who gets an email. `viewer` is excluded: read access to
    | a dashboard is not a reason to be paged.
    |
    | `guard` is pinned explicitly and read from the auth config rather than
    | left to Spatie's default resolution. Both `web` and `api` use the `users`
    | provider, so an AUTH_GUARD override would silently match zero roles — and
    | zero recipients is an alert that never arrives, with no error anywhere.
    |
    */
    'recipients' => [
        'roles' => ['admin', 'operator'],
        'guard' => env('NOTIFICATIONS_RECIPIENT_GUARD', config('auth.defaults.guard', 'api')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storm suppression
    |--------------------------------------------------------------------------
    |
    | Dedupe (the unique index on notification_logs) collapses repeats of the
    | SAME subject. It cannot collapse a provider outage, which produces one
    | genuinely distinct failure per candidate. This window does.
    |
    | Inside the window a row is still written — status `suppressed`, reason
    | `window`, no email. When the window elapses the next occurrence sends and
    | carries `suppressed_carried_count`, so the operator can tell one broken
    | endpoint from a total outage. Silence is never total.
    |
    | `window_seconds_by_type` overrides the global value per notification type.
    | Keys MUST be NotificationType values; a typo does not error, it just never
    | matches — which is why a test pins them.
    |
    */
    'suppression' => [
        'window_seconds' => (int) env('NOTIFICATIONS_SUPPRESSION_WINDOW_SECONDS', 900),
        'window_seconds_by_type' => [
            // e.g. 'scoring_failed' => 1800,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dispatch
    |--------------------------------------------------------------------------
    |
    | Retry policy for SendOperatorNotificationJob. `tries` and `backoff_seconds`
    | are read by the job's tries()/backoff() methods — never hardcoded there.
    |
    | `last_error_max_chars` truncates notification_logs.last_error. Truncation
    | happens AFTER secret redaction, never before: truncating first can leave a
    | partial secret intact at the cut.
    |
    | THERE IS NO `queue` KEY, AND THAT IS LOAD-BEARING.
    | config/queue.php defaults runtime.worker_queues to ['default']. A
    | `notifications` queue name that no worker consumes would strand every
    | alert silently — the worst possible failure for a capability whose only
    | job is to tell a human that something broke. C10 added a delivery.queue
    | key; C12 does not. If one is ever added it MUST land in the same change as
    | the matching worker_queues entry.
    |
    */
    'dispatch' => [
        'tries' => (int) env('NOTIFICATIONS_DISPATCH_TRIES', 3),
        'backoff_seconds' => [30, 120],
        'last_error_max_chars' => 1000,
    ],

];
