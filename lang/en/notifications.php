<?php

declare(strict_types=1);

/**
 * Operator notification copy (C12, D7) — the first outbound-copy namespace in
 * this repository.
 *
 * User-facing text ONLY. Machine values — notification_type, status,
 * suppression_reason — are never localized and never appear here.
 */
return [

    'webhook_delivery_dead' => [
        'subject' => 'BEAI: webhook delivery failed permanently',
        'greeting' => 'A webhook could not be delivered',
        'body' => 'We tried :attempts times to deliver an evaluation to your endpoint and every attempt failed. The receiving system has not been told about this candidate.',
        'action' => 'Open the dashboard',
        'outro' => 'Until the endpoint accepts deliveries again, results for this project will not reach your system.',
    ],

    'scoring_failed' => [
        'subject' => 'BEAI: an evaluation could not be produced',
        'greeting' => 'An evaluation failed',
        'body' => 'A candidate completed their interview but the evaluation could not be produced. Their answers are safe; only the scoring step failed.',
        'action' => 'Open the dashboard',
        'outro' => 'No action is required from the candidate.',
    ],

    /*
     | The carried count (D4). Shown ONLY when suppressed occurrences
     | accumulated behind the window, so the operator can tell one broken
     | endpoint from a total outage. Silence is never total.
     */
    'suppressed_carried' => '{1} :count further failure was suppressed in the last :minutes minutes.|[2,*] :count further failures were suppressed in the last :minutes minutes.',

    'footer' => 'You are receiving this because you hold an operator role in :organization.',
];
