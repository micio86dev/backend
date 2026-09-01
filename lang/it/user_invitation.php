<?php

declare(strict_types=1);

/**
 * L'invito che riceve un nuovo utente del backoffice.
 *
 * Standard e statico, non personalizzabile per tenant (ratificato il
 * 2026-09-01): un amministratore che può modificare il testo di un messaggio
 * il cui unico scopo è recapitare un link può rimuovere quel link.
 *
 * Reso nella lingua dell'utente DESTINATARIO, mai in quella del worker.
 */
return [

    'subject' => 'Sei stato invitato su BEAI',
    'greeting' => 'Benvenuto su BEAI',

    'intro' => ':inviter ti ha aggiunto a :organization su BEAI.',

    'what_it_is' => 'BEAI conduce valutazioni delle soft skill tramite un colloquio '
        .'vocale automatizzato. I candidati parlano con un intervistatore AI e BEAI '
        .'valuta quanto hanno detto rispetto ad ancore comportamentali di competenza, '
        .'producendo una valutazione strutturata.',

    'your_role_heading' => 'Cosa puoi fare',

    'role_admin' => 'Sei un AMMINISTRATORE. Puoi fare tutto ciò che fa un operatore e, '
        .'in più, sei l\'unico a poter modificare le impostazioni dell\'organizzazione '
        .'— identità visiva, chiavi API, credenziali dei modelli e i template avatar '
        .'che ogni candidato incontra — invitare e disattivare utenti, ed eliminare '
        .'progetti e template.',

    'role_operator' => 'Sei un OPERATORE. Gestisci il lavoro quotidiano: crei e '
        .'configuri i progetti, inviti i candidati, segui i colloqui mentre avvengono '
        .'e leggi le valutazioni che ne derivano. Le impostazioni dell\'organizzazione '
        .'e le eliminazioni sono riservate agli amministratori.',

    'role_viewer' => 'Sei un OSSERVATORE. Puoi leggere tutto ciò che la tua '
        .'organizzazione ha — progetti, candidati, trascrizioni e valutazioni — e non '
        .'puoi modificare nulla. Niente di ciò che fai può alterare una valutazione in '
        .'corso o un risultato già prodotto.',

    'how_to_start' => 'Imposta la tua password con il pulsante qui sotto, poi accedi.',
    'action' => 'Imposta la password',
    'url_fallback' => 'Se il pulsante non funziona, copia questo indirizzo nel browser:',
    'expiry' => 'Questo link scade tra :minutes minuti e può essere usato una sola '
        .'volta. Dopodiché usa "Password dimenticata" nella pagina di accesso per '
        .'ottenerne uno nuovo.',

    'salutation' => 'Il team BEAI',

];
