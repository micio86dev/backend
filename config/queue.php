<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    | queue-worker-scheduler PR3: default flipped database -> redis. Safe
    | as of this change (not before): PR1 landed ext-redis in the runtime
    | image (Dockerfile) and raised retry_after to a value that clears the
    | timeout/retry_after invariant on BOTH connections; PR2 landed the
    | beai:queue-work wrapper that structurally forbids a worker-level
    | --tries override. `jobs`/`job_batches` tables are NOT dropped, so
    | QUEUE_CONNECTION=database is a one-line rollback if ever needed.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 1500),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 1500),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime — Worker Process Configuration
    |--------------------------------------------------------------------------
    |
    | Single source of truth for every number the `beai:queue-work` wrapper
    | command (App\Console\Commands\QueueWorkCommand) forwards to the
    | underlying `queue:work` invocation. The reliability invariant is:
    |
    |     max(declared job $timeout) < worker_timeout < connections.*.retry_after
    |
    | AND ScoreEvaluationJob::$timeout independently clears the derived
    | ceiling: App\Support\Queue\QueueRuntimeInvariant::MAX_ROLE_COMPETENCIES
    | (single source of truth) x scoring.anthropic.timeout_seconds x 1.1,
    | with App\Support\Queue\QueueRuntimeInvariant::CONFIG_INDEPENDENT_FLOOR_SECONDS
    | (600s) as the floor (queue-runtime/spec.md).
    |
    | worker_timeout:      forwarded as `queue:work --timeout`. Kills a job
    |                       that runs longer than this.
    | worker_max_time:     forwarded as `queue:work --max-time`. Worker
    |                       process exits cleanly after this many seconds
    |                       (deploy/restart hygiene), not a per-job bound.
    | worker_memory_mb:    forwarded as `queue:work --memory`.
    | worker_queues:       forwarded as `queue:work --queue` (comma-joined).
    | worker_sleep_seconds: forwarded as `queue:work --sleep`. Seconds to
    |                       sleep when no job is available (framework
    |                       default is 3 — see WorkCommand's --sleep=3).
    | stall_threshold_seconds: DEPTH-based stall signal. Used by the queue
    |                       health probe: the queue is "stalled" when depth
    |                       > 0 AND the age since the last successfully
    |                       processed job exceeds this. 300s is appropriate
    |                       here because under healthy operation a pending
    |                       job should be PICKED UP within seconds — this
    |                       threshold is about the worker failing to start
    |                       consuming at all, not about how long any single
    |                       job takes to run.
    | reserved_job_stall_threshold_seconds: RESERVATION-based stall signal —
    |                       deliberately a SEPARATE, larger threshold, not
    |                       the same number as stall_threshold_seconds
    |                       above. A legitimately-running ScoreEvaluationJob
    |                       can validly stay "reserved" for close to
    |                       worker_timeout (1260s) — that is normal, not a
    |                       stall. Only a reservation held meaningfully
    |                       LONGER than worker_timeout indicates the worker
    |                       that reserved it crashed or was restarted
    |                       (Symfony's own --timeout enforcement never let a
    |                       healthy worker hold a reservation past
    |                       worker_timeout). Default is worker_timeout + a
    |                       60s buffer = 1320s, deliberately still below
    |                       retry_after (1500s) so this signal fires BEFORE
    |                       Laravel's own migrateExpiredJobs() silently
    |                       requeues the job — giving an operator ~180s of
    |                       lead time on the exact failure mode retry_after
    |                       was raised to 1500s to tolerate (queue-runtime/
    |                       spec.md; see also App\Support\Queue\ReservedJobAgeProbe).
    |
    */

    'runtime' => [
        'worker_timeout' => (int) env('QUEUE_WORKER_TIMEOUT', 1260),
        'worker_max_time' => (int) env('QUEUE_WORKER_MAX_TIME', 3600),
        'worker_memory_mb' => (int) env('QUEUE_WORKER_MEMORY_MB', 512),
        'worker_queues' => explode(',', (string) env('QUEUE_WORKER_QUEUES', 'default')),
        'worker_sleep_seconds' => (int) env('QUEUE_WORKER_SLEEP_SECONDS', 3),
        'stall_threshold_seconds' => (int) env('QUEUE_STALL_THRESHOLD_SECONDS', 300),
        'reserved_job_stall_threshold_seconds' => (int) env('QUEUE_RESERVED_JOB_STALL_THRESHOLD_SECONDS', 1320),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance — Scheduled Queue-Table Pruning
    |--------------------------------------------------------------------------
    |
    | Retention windows for the scheduled `queue:prune-failed` and
    | `queue:prune-batches` tasks (registered via ->withSchedule() in
    | bootstrap/app.php, both ->onOneServer()). Queue hygiene, NOT a domain
    | concern — this is distinct from any future GDPR candidate-data purge
    | (C13's own retention policy, unrelated to these framework tables).
    |
    | 168 hours (7 days) balances the operational debugging window (enough
    | time to notice and investigate a failed job or an incomplete batch)
    | against unbounded table growth. failed_jobs payloads for this app's
    | jobs carry only scalar IDs (ScoreEvaluationJob takes an int
    | participantId, DeliverWebhookJob an int deliveryId — see their
    | constructors), not participant PII, so the retention window is a
    | pure operability/storage tradeoff, not a data-minimization one; only
    | the free-text `exception` column could carry incidental data, which
    | is why this stays finite and short rather than indefinite. Env-driven
    | so a future change (e.g. C13) can shorten it without a code change.
    |
    */

    'maintenance' => [
        'failed_jobs_retention_hours' => (int) env('QUEUE_FAILED_JOBS_RETENTION_HOURS', 168),
        'batches_retention_hours' => (int) env('QUEUE_BATCHES_RETENTION_HOURS', 168),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
