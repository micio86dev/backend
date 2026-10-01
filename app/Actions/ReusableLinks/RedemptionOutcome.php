<?php

declare(strict_types=1);

namespace App\Actions\ReusableLinks;

use App\Models\Project;

/**
 * The result of {@see RedeemReusableInterviewLink::handle()}.
 *
 * A value object so the controller maps an outcome to a response without
 * re-deriving anything, and so no failure path can carry a secret: the only
 * payload is the minted credential on success and the project (for its error
 * redirect) on a refusal.
 */
final readonly class RedemptionOutcome
{
    private function __construct(
        public RedemptionStatus $status,
        public ?string $accessToken = null,
        public ?Project $project = null,
    ) {}

    /**
     * A visitor was created; `$accessToken` is their candidate JWT.
     */
    public static function redeemed(string $accessToken): self
    {
        return new self(RedemptionStatus::Redeemed, accessToken: $accessToken);
    }

    /**
     * Nothing redeemable: the one outcome for every unusable token.
     */
    public static function notFound(): self
    {
        return new self(RedemptionStatus::NotFound);
    }

    /**
     * A valid link on a project that is closed or not interviewable.
     */
    public static function refused(Project $project): self
    {
        return new self(RedemptionStatus::Refused, project: $project);
    }

    /**
     * The email is already enrolled in the link's project. Payload-free on
     * purpose: nothing about the existing participant is carried, so nothing
     * can leak and nothing can be resumed.
     */
    public static function duplicate(): self
    {
        return new self(RedemptionStatus::Duplicate);
    }
}
