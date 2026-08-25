<?php

declare(strict_types=1);

/**
 * Interview avatar completion-signal phrases (C7a follow-up — interview-frontend addendum).
 *
 * These are platform-default localized UX strings — institutional avatar chrome, NOT
 * per-tenant/BARS content. They are the SAME for every project of a given language.
 *
 * The frontend (C7b) consumes these as the SOLE source for HeyGen completion-signal
 * detection:
 *   - end_phrase   — spoken by the avatar to close an intermediate question.
 *   - final_phrase — spoken by the avatar to close the final question (thank-you).
 *
 * Adding a new language = adding lang/{locale}/interview.php. Missing languages fall
 * back to the platform default language (config app.fallback_locale) at resolution time.
 *
 * `opening.*` (PR3, design D9): il saluto iniziale pronunciato dall'avatar per
 * ciascuna variante, composto da App\Services\Conversation\OpeningTextComposer.
 * `:competency` viene sostituito con il nome visualizzato della competenza.
 *
 * OGNI variante TERMINA CON UNA DOMANDA, e non è cosmesi. Prima annunciavano
 * soltanto l'argomento: il provider pronunciava la frase, l'LLM non aveva alcun
 * turno utente a cui rispondere e RESTAVA IN ATTESA. Il candidato doveva dire
 * "ok" prima che arrivasse la prima domanda vera — un turno morto a ogni
 * competenza, osservato in produzione il 2026-08-25.
 *
 * `resume` invita a PROSEGUIRE, non a iniziare: chi riprende era già dentro un
 * episodio, e chiedergliene uno nuovo perderebbe quanto già raccontato.
 */
return [
    'end_phrase' => 'Passiamo alla prossima domanda.',
    'final_phrase' => 'Grazie per il tuo tempo.',

    'opening' => [
        'first' => 'Ciao, e benvenuto! Iniziamo parlando di :competency. Raccontami un episodio specifico e concreto in cui questo è emerso nel tuo lavoro: cosa è successo?',
        'next' => 'Bene, passiamo ora a parlare di :competency. Raccontami un episodio specifico in cui questo è emerso: cosa è successo?',
        'resume' => 'Riprendiamo da dove eravamo rimasti, parlando di :competency. Vai pure avanti da dove ti eri fermato.',
        'retry' => 'Scusami, c\'è stato un problema tecnico da parte nostra. Riprendiamo da capo con :competency. Raccontami un episodio specifico in cui questo è emerso: cosa è successo?',
    ],
];
