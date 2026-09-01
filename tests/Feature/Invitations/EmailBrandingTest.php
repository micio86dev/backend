<?php

declare(strict_types=1);

/**
 * The words are standard; the chrome is per-tenant.
 *
 * CLAUDE.md ruling 10, ratified 2026-09-01: every transactional template is
 * multilingual with placeholders and NOT editable by tenant admins — an admin
 * who can edit the body of a password-reset mail can remove the link it exists
 * to deliver. Branding still applies, and this is what "branding" means in an
 * email: the colour, and only the colour.
 *
 * NOT THE LOGO. Every major client blocks remote images by default, so a tenant
 * logo renders as a broken-image icon on a message a candidate is deciding
 * whether to trust, and fetching it leaks an open-tracking signal to whoever
 * serves it. That reasoning already removed Laravel's own hotlinked logo from
 * the header. A colour is CSS: it always renders, with images off, everywhere.
 */

use App\Notifications\UserInvitationNotification;
use App\Support\Mail\EmailBranding;

/**
 * The real message, rendered.
 *
 * `view('mail::button')` cannot be rendered directly — the `mail` namespace is
 * registered by the mail renderer, not by the view finder — and reaching past
 * that would test a template in isolation from the machinery that decides
 * whether it is ever reached. Rendering an actual notification exercises the
 * whole path.
 */
function renderedInvitation(): string
{
    return (new UserInvitationNotification(
        'https://backoffice.test/reset-password/token',
        60,
        'operator',
        'Ada',
        'Acme',
    ))->toMail(null)->render()->toHtml();
}

test('a configured colour reaches the rendered button', function (): void {
    $branding = app(EmailBranding::class);
    $branding->set('#ff6600');

    $html = renderedInvitation();

    expect($html)->toContain('background-color: #ff6600')
        // The borders too, and that is not thoroughness for its own sake: this
        // template builds the button's padding out of BORDERS, because Outlook
        // ignores padding on an anchor. Recolouring only the background would
        // draw a purple frame around a tenant-coloured button.
        ->and($html)->toContain('border-color: #ff6600');
});

test('an organization with no colour gets NO inline style, not a default one', function (): void {
    // The theme stylesheet already carries the Quint purple. Writing it here
    // would be a second copy of the brand constant, and the two would drift
    // the first time the palette changes.
    app(EmailBranding::class)->forget();

    $html = renderedInvitation();

    // The BUTTON carries no inline background. The theme stylesheet is inlined
    // by the mail renderer, so `background-color` appears elsewhere in the
    // document — what must be absent is an override on the action link.
    expect($html)->not->toContain('style="background-color:');
});

test('a value that is not a plain six-digit hex is REFUSED, not interpolated', function (): void {
    // This ends up inside a `style` attribute. The API validates with an
    // anchored regex and the database has a CHECK, and it is re-validated here
    // anyway: a writer that trusts its input because something upstream
    // promised to check is how an injection survives a refactor.
    $branding = app(EmailBranding::class);
    $branding->set('#ff6600" onload="alert(1)');

    expect($branding->primaryColor())->toBeNull();

    $html = renderedInvitation();

    expect($html)->not->toContain('onload');
});

test('it does not survive its own send', function (): void {
    // A queue worker is a long-lived process handling one tenant's mail after
    // another's. A colour that outlived its send would put one organization's
    // brand on another organization's message — which is worse than unbranded,
    // because it is confidently wrong.
    $branding = app(EmailBranding::class);
    $branding->set('#ff6600');
    $branding->forget();

    expect($branding->primaryColor())->toBeNull();
});
