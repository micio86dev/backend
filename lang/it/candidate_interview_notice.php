<?php

declare(strict_types=1);

/**
 * L'avviso anticipato che riceve un candidato pianificato prima dell'arrivo
 * del link del colloquio (interview-scheduling, design AD-9).
 *
 * Standard e statico (ratificato il 2026-09-01): multilingua con segnaposto,
 * non modificabile dagli amministratori del tenant — stessa disciplina di
 * `candidate_invitation.php`.
 *
 * Reso nella lingua del PROGETTO (o quella del candidato, se impostata), mai
 * in quella dell'operatore o del worker.
 *
 * Non contiene, deliberatamente, alcun link né alcuna data di scadenza.
 */
return [

    'subject' => 'Il tuo colloquio per :project è in arrivo',

    'greeting' => 'Ciao :name',

    'intro' => ':organization ha pianificato il tuo colloquio per :project.',

    'what_happens_next' => 'Il link per iniziare il colloquio arriverà a breve in una '
        .'email separata, non appena si raggiungerà l\'orario pianificato — al momento '
        .'non devi fare nulla.',

    'requirements' => 'Quando arriverà: usa un computer desktop o portatile con Chrome, '
        .'Edge, Opera o Safari. Telefoni, tablet e Firefox non sono supportati.',

    'salutation' => 'A presto',

];
