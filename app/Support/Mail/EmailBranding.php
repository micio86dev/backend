<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * The organization's colour, for the duration of one send.
 *
 * CLAUDE.md ruling 10: transactional email is standard and static — the WORDS
 * are the same for every tenant and not editable by admins — but the CHROME is
 * per-tenant. This is the chrome.
 *
 * A SCOPED BINDING, SET BY THE JOB, CLEARED AFTER. Not a singleton and not a
 * static: a queue worker is a long-lived process handling one tenant's mail
 * after another's, and a colour that outlived its send would put one
 * organization's brand on another organization's message. `scoped()` is reset
 * per request and per job, and `forget()` below makes the boundary explicit
 * rather than relying on it.
 *
 * ONLY THE COLOUR, DELIBERATELY NOT THE LOGO. Every major client blocks remote
 * images by default, so a tenant logo would render as a broken-image icon on a
 * message a candidate is deciding whether to trust — and fetching it leaks an
 * open-tracking signal to whoever serves it. That reasoning is already recorded
 * in `resources/views/vendor/mail/html/header.blade.php`, which removed
 * Laravel's own hotlinked logo for exactly this. A colour is CSS: it always
 * renders, with images off, in every client.
 */
final class EmailBranding
{
    private ?string $primaryColor = null;

    /**
     * A `#rrggbb` string, or null for "use the product's own".
     *
     * Re-validated here even though the API validates it with an anchored
     * regex AND a database CHECK. This value is interpolated into a `style`
     * attribute, and a writer that trusts its input because something upstream
     * promised to check is how an injection survives a refactor.
     */
    public function set(?string $color): void
    {
        $this->primaryColor = is_string($color) && preg_match('/\A#[0-9a-fA-F]{6}\z/', $color) === 1
            ? $color
            : null;
    }

    public function forget(): void
    {
        $this->primaryColor = null;
    }

    /**
     * Null when the organization has configured no colour — and the caller
     * then emits NOTHING rather than a default. The theme stylesheet already
     * carries the Quint purple; writing it here would be a second copy of the
     * brand constant for the two to drift apart.
     */
    public function primaryColor(): ?string
    {
        return $this->primaryColor;
    }
}
