<?php

declare(strict_types=1);

namespace App\DTOs\Conversation;

/**
 * What the avatar's opening line actually said, as the system prompt must
 * describe it.
 *
 * The opening is spoken by the provider before the prompt runs, so the model
 * only knows what it asked if the prompt tells it. Three shapes exist:
 *   - `primary(n)`       — a fresh competency; the opening was primary n
 *                          (always 1 on the /start path).
 *   - `resumed(k, n)`    — a resumed competency where k of n primaries were
 *                          already asked; the opening re-asked the pending
 *                          primary k+1, or the last one when k = n.
 *   - `fallback()`       — the competency has no primaries (reachable only
 *                          while the interviewability gate is off); the
 *                          opening was the generic episode question from
 *                          `interview.opening.fallback`.
 */
final readonly class SpokenOpening
{
    private function __construct(
        public ?int $primaryNumber,
        public bool $resumed,
        public int $primariesAskedBefore,
    ) {}

    /**
     * @throws \InvalidArgumentException When `$number` is below 1.
     */
    public static function primary(int $number): self
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('SpokenOpening: a primary number starts at 1.');
        }

        return new self($number, false, $number - 1);
    }

    /**
     * @param  int  $askedBefore  Distinct primaries asked before the interruption.
     * @param  int  $total  Size of the primary set; must be at least 1.
     *
     * @throws \InvalidArgumentException When `$total` is below 1.
     */
    public static function resumed(int $askedBefore, int $total): self
    {
        if ($total < 1) {
            throw new \InvalidArgumentException('SpokenOpening: a resumed primary opening needs at least one primary.');
        }

        $askedBefore = max(0, min($askedBefore, $total));

        return new self(min($askedBefore + 1, $total), true, $askedBefore);
    }

    public static function fallback(bool $resumed = false): self
    {
        return new self(null, $resumed, 0);
    }

    /**
     * True when the primary the opening spoke had already been asked before
     * (a resume after every primary was asked).
     */
    public function isReAskOfAskedPrimary(): bool
    {
        return $this->primaryNumber !== null && $this->primariesAskedBefore >= $this->primaryNumber;
    }
}
