<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * Why the configured mail transport cannot deliver (mail-delivery-guard).
 *
 * A code AND a detail, not one or the other. The code is what a health
 * surface and a boot gate branch on; the detail is what tells an operator
 * WHICH member of a failover chain is the dead end. "Refused" sends someone
 * looking; "refused: the chain reaches 'log'" tells them what to change.
 */
final readonly class MailDeliveryRefusal
{
    /**
     * `mailer` and `deadEnd` are carried STRUCTURED, not only inside `detail`.
     * Callers render their own wording — the self-test has messages of its own
     * that operators have learned to read — and reconstructing a name by
     * parsing a sentence is how copy edits turn into behaviour changes.
     *
     * @param  'non_delivering_mailer'|'non_delivering_chain'|'unresolvable_transport'  $code
     * @param  string|null  $deadEnd  The first chain member that delivers nothing, when there is one.
     */
    public function __construct(
        public string $code,
        public string $mailer,
        public string $detail,
        public ?string $deadEnd = null,
    ) {}
}
