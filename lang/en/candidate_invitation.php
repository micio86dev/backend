<?php

declare(strict_types=1);

/**
 * The invitation a candidate receives for one assessment.
 *
 * STANDARD AND STATIC, per the 2026-09-01 ruling: multilingual with
 * placeholders, not editable by tenant admins. An admin who can edit the body
 * of a message whose entire purpose is to deliver a link can remove the link.
 *
 * Rendered in the language of the PROJECT, not of the operator who sent it and
 * not of the queue worker. The interview itself is conducted in the project's
 * language; an invitation in a different one is a promise the product then
 * breaks in the first thirty seconds.
 *
 * The tone is deliberately reassuring rather than neutral. A candidate is being
 * asked to talk to a camera and be scored on it, usually for a job they want.
 * Telling them what will happen, how long it takes, and that they can prepare
 * is not padding — it is the difference between an assessment and an ambush.
 */
return [

    'subject' => 'Your interview for :project',

    'greeting' => 'Hello :name',

    'intro' => ':organization has invited you to a short interview for :project.',

    'what_happens' => 'You will speak with an AI interviewer that asks about real '
        .'situations you have handled. There are no trick questions and no right '
        .'answers to guess: it is asking what you actually did, so concrete examples '
        .'from your own experience are exactly what it is looking for.',

    'before_you_start' => 'Before you begin: find a quiet place, allow around 30 '
        .'minutes without interruption, and check your camera and microphone. You will '
        .'be asked to grant access to both, and you can test them on the first screen.',

    'requirements' => 'Use a desktop or laptop with Chrome, Edge, Opera or Safari. '
        .'Phones, tablets and Firefox are not supported.',

    'action' => 'Start the interview',

    'url_fallback' => 'If the button does not work, copy this address into your browser:',

    'expiry' => 'This link is personal to you and stops working on :date.',

    'salutation' => 'Good luck',

];
