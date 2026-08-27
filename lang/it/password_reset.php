<?php

declare(strict_types=1);

/**
 * Copy dell'email di reimpostazione password (self-service-password-reset AD-6).
 *
 * Solo testo rivolto all'utente, reso nella lingua dell'utente DESTINATARIO —
 * mai nella lingua in cui è stato avviato il worker della coda.
 *
 * Volutamente assenti: indirizzo IP e user agent di chi ha fatto la richiesta.
 */
return [

    'subject' => 'Reimposta la tua password BEAI',
    'greeting' => 'Reimpostazione password',
    'line_1' => 'Abbiamo ricevuto una richiesta di reimpostazione della password del tuo account BEAI.',
    'action' => 'Scegli una nuova password',
    'url_fallback' => 'Se il pulsante non funziona, copia questo indirizzo nel browser:',
    'expiry' => 'Questo link scade tra :minutes minuti e può essere usato una sola volta.',
    'not_you' => 'Se non hai richiesto tu questa operazione, la tua password non è cambiata e non devi fare nulla.',
    'salutation' => 'Il team BEAI',

];
