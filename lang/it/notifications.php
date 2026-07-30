<?php

declare(strict_types=1);

/**
 * Testi delle notifiche operative (C12, D7).
 *
 * Solo testo rivolto all'utente. I valori macchina — notification_type, status,
 * suppression_reason — non sono mai localizzati e non compaiono qui.
 */
return [

    'webhook_delivery_dead' => [
        'subject' => 'BEAI: consegna webhook fallita in modo definitivo',
        'greeting' => 'Non è stato possibile consegnare un webhook',
        'body' => 'Abbiamo provato :attempts volte a consegnare una valutazione al vostro endpoint e ogni tentativo è fallito. Il sistema ricevente non è stato informato di questo candidato.',
        'action' => 'Apri la dashboard',
        'outro' => "Finché l'endpoint non accetta nuovamente le consegne, i risultati di questo progetto non raggiungeranno il vostro sistema.",
    ],

    'scoring_failed' => [
        'subject' => 'BEAI: non è stato possibile produrre una valutazione',
        'greeting' => 'Una valutazione è fallita',
        'body' => "Un candidato ha completato l'intervista ma non è stato possibile produrre la valutazione. Le sue risposte sono al sicuro: è fallita solo la fase di scoring.",
        'action' => 'Apri la dashboard',
        'outro' => 'Non è richiesta alcuna azione da parte del candidato.',
    ],

    /*
     | Il conteggio riportato (D4). Mostrato SOLO quando ci sono occorrenze
     | soppresse accumulate dietro la finestra, così l'operatore distingue un
     | singolo endpoint rotto da un'interruzione totale.
     */
    'suppressed_carried' => '{1} :count ulteriore fallimento è stato soppresso negli ultimi :minutes minuti.|[2,*] :count ulteriori fallimenti sono stati soppressi negli ultimi :minutes minuti.',

    'footer' => 'Ricevi questo messaggio perché hai un ruolo operativo in :organization.',
];
