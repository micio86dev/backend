<?php

declare(strict_types=1);

/**
 * The invitation a new backoffice user receives (C12 adjacent, but not a C12
 * `notifications` trigger — that capability's trigger set is exactly two
 * events, and this is neither).
 *
 * STANDARD AND STATIC, NOT PER-TENANT. Ratified 2026-09-01: every template is
 * multilingual with placeholders and is NOT editable by tenant admins. An
 * admin able to edit the body of a message whose entire purpose is to deliver
 * a link can remove the link, and the configuration surface was judged too
 * complex for the audience. The CHROME is per-tenant; the WORDS are not.
 *
 * WHY THE ROLE PARAGRAPHS ARE THREE SEPARATE STRINGS
 * ---------------------------------------------------
 * "You have been given the operator role" tells someone nothing they can act
 * on. What they need is what the product IS and what THEY can do in it, and
 * those differ enough between the three roles that one paragraph with a
 * substituted role name would be vague for all of them. The message picks
 * exactly one.
 *
 * Rendered in the TARGET USER's locale, never the queue worker's.
 */
return [

    'subject' => 'You have been invited to BEAI',
    'greeting' => 'Welcome to BEAI',

    'intro' => ':inviter added you to :organization on BEAI.',

    // What the product is, before what their role is. Somebody who has never
    // heard of BEAI cannot make sense of "you can review evaluations".
    'what_it_is' => 'BEAI runs soft-skill assessments as an automated voice interview. '
        .'Candidates talk to an AI interviewer, and BEAI scores what they said against '
        .'behavioural competency anchors, producing a structured evaluation.',

    'your_role_heading' => 'What you can do',

    'role_admin' => 'You are an ADMINISTRATOR. You can do everything an operator can, '
        .'and you alone can change organization settings — branding, API keys, model '
        .'credentials and the avatar templates every candidate meets — invite and '
        .'deactivate users, and delete projects and templates.',

    'role_operator' => 'You are an OPERATOR. You run the day-to-day work: create and '
        .'configure projects, invite candidates to them, follow interviews as they '
        .'happen, and read the evaluations that come back. Organization settings and '
        .'deletions are reserved to administrators.',

    'role_viewer' => 'You are an OBSERVER. You can read everything your organization '
        .'has — projects, candidates, transcripts and evaluations — and change none of '
        .'it. Nothing you do can alter an assessment in progress or a result already '
        .'produced.',

    // Every role, whatever they may do, starts the same way.
    'how_to_start' => 'Set your password with the button below, then sign in.',
    'action' => 'Set your password',
    'url_fallback' => 'If the button does not work, copy this address into your browser:',
    'expiry' => 'This link stops working in :minutes minutes, and can only be used once. '
        .'After that, use "Forgot password" on the sign-in page to get a new one.',

    'salutation' => 'The BEAI team',

];
