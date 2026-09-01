<?php

declare(strict_types=1);

/**
 * L'invito che riceve un candidato per una singola valutazione.
 *
 * Standard e statico (ratificato il 2026-09-01): multilingua con segnaposto,
 * non modificabile dagli amministratori del tenant.
 *
 * Reso nella lingua del PROGETTO, non in quella dell'operatore che lo invia né
 * del worker: il colloquio si svolge nella lingua del progetto, e un invito in
 * un'altra lingua è una promessa che il prodotto smentisce nei primi trenta
 * secondi.
 */
return [

    'subject' => 'Il tuo colloquio per :project',

    'greeting' => 'Ciao :name',

    'intro' => ':organization ti ha invitato a un breve colloquio per :project.',

    'what_happens' => 'Parlerai con un intervistatore AI che ti chiederà di situazioni '
        .'reali che hai affrontato. Non ci sono domande trabocchetto né risposte giuste '
        .'da indovinare: ti verrà chiesto cosa hai fatto davvero, quindi esempi concreti '
        .'presi dalla tua esperienza sono esattamente ciò che serve.',

    'before_you_start' => 'Prima di iniziare: scegli un posto tranquillo, tieni libera '
        .'circa mezz\'ora senza interruzioni e verifica videocamera e microfono. Ti verrà '
        .'chiesto di consentire l\'accesso a entrambi e potrai provarli nella prima '
        .'schermata.',

    'requirements' => 'Usa un computer desktop o portatile con Chrome, Edge, Opera o '
        .'Safari. Telefoni, tablet e Firefox non sono supportati.',

    'action' => 'Inizia il colloquio',

    'url_fallback' => 'Se il pulsante non funziona, copia questo indirizzo nel browser:',

    'expiry' => 'Questo link è personale e smette di funzionare il :date.',

    'salutation' => 'In bocca al lupo',

];
