<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Participant\PlaceholderEmail;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * NotPlaceholderEmail (reusable-link-visitor-identity, design VD-4 and VD-23): an
 * address under a reserved placeholder domain is not an address a person can give.
 *
 * Both reserved domains (`@invalid.beai.local` and `@purged.beai.invalid`) belong
 * to addresses BEAI writes itself. Without this rule a visitor, an operator or a
 * calling system could type one, and the row would then claim to be synthesised
 * or purged: the invitation job's refusal would be wrong about it, and a typed
 * address could collide with the placeholder the retention purge derives for
 * another participant.
 *
 * The predicate is `PlaceholderEmail::is()`, not a copy of the domains: the
 * spelling lives in that class only. The failure is the framework's own
 * invalid-email message, translated, so it reads like any other bad address and
 * discloses nothing about the reserved domains.
 *
 * STRICT by default. The one exception is a participant's OWN placeholder, and it
 * exists only on the two paths that can re-issue a link for an existing
 * `candidate_ref` (the operator entry link and the M2M sso-link mint): the
 * backoffice participant-detail re-issue sends the stored email back, so a legacy
 * anonymous visitor or a purged participant must stay re-issuable. Such a path
 * passes the NAME of its reference field (`new NotPlaceholderEmail('candidate_ref')`)
 * and the rule reads the reference from the validated data through
 * `DataAwareRule`, never from the live request (Scramble evaluates inline rule
 * arrays, and a live-request read there silently empties the exported schema). An
 * own placeholder is project-unique, so it cannot collide with another row, and
 * non-routable, so it is never mailed.
 *
 * A value that is not a string is left to the `string` and `email` rules next to
 * this one.
 */
final class NotPlaceholderEmail implements DataAwareRule, ValidationRule
{
    /**
     * The data under validation, set by the validator before `validate()` runs.
     *
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * @param  string|null  $ownCandidateRefKey  the dotted key of the request field holding the candidate reference whose OWN placeholder is accepted; null is strict
     */
    public function __construct(private readonly ?string $ownCandidateRefKey = null) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PlaceholderEmail::is($value) || $this->isOwnPlaceholder($value)) {
            return;
        }

        $fail('validation.email')->translate();
    }

    /**
     * Whether `$email` is the OWN placeholder of the candidate reference this
     * rule was told to read.
     */
    private function isOwnPlaceholder(string $email): bool
    {
        if ($this->ownCandidateRefKey === null) {
            return false;
        }

        $reference = data_get($this->data, $this->ownCandidateRefKey);

        return is_string($reference) && PlaceholderEmail::isOwn($email, $reference);
    }
}
