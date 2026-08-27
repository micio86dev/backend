<?php

declare(strict_types=1);

/**
 * Password-reset email copy (self-service-password-reset AD-6).
 *
 * User-facing text ONLY, rendered in the TARGET USER's locale — never the
 * locale the queue worker happens to have booted in.
 *
 * Deliberately absent: the requesting IP address and user agent. An IP in an
 * email is weak security theatre and a small privacy leak; the reassurance line
 * below is what actually reduces support contacts (proposal question 6).
 */
return [

    'subject' => 'Reset your BEAI password',
    'greeting' => 'Password reset',
    'line_1' => 'We received a request to reset the password for your BEAI account.',
    'action' => 'Choose a new password',
    'url_fallback' => 'If the button does not work, copy this address into your browser:',
    'expiry' => 'This link stops working in :minutes minutes, and can only be used once.',
    'not_you' => 'If you did not request this, your password has not changed and no action is needed.',
    'salutation' => 'The BEAI team',

];
