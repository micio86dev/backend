<?php

declare(strict_types=1);

namespace App\Support\Demo;

/**
 * The hand-authored demo dataset (design D4/D5, volume table).
 *
 * Plain PHP arrays — no factories (`fakerphp/faker` is require-dev only and
 * absent from the production image), no JSON file (a second path-resolution
 * failure mode `FrameworkCatalogSeeder`'s constructor already documents
 * fighting), no seeded PRNG (unreadable, and incapable of writing an Italian
 * sentence that is also a valid BARS excerpt).
 *
 * The fixture authors only INPUTS: score vectors in {1,3,5,-1} and transcript
 * text. Every derived number — mean, reliability, validity, completion
 * status, proctoring band — is computed by the same production classes that
 * score a real interview. `DemoDatasetValidator` checks this fixture against
 * the live catalog before a single row is written.
 *
 * Competency-set correction (verified against the repository, not assumed):
 * the design's volume table put PRS/STG/DRV/COM/COL under the FLL project,
 * mirroring ICO. `database/framework/bars/FLL.json` does not define BARS
 * indicators for PRS, DRV, COM or COL — only STG, INN, CSF, OPX, INS, INF,
 * RES and LRN are authored for FLL (confirmed by
 * `SeededCountCorrectnessTest::'per-role BARS-covered competency counts
 * match design'`, which asserts FLL's BARS-covered count is 8, not 15).
 * Scoring a competency the catalog has no indicators for is not a state the
 * product can produce, so P2's competency set here is STG/INN/CSF/OPX/INS —
 * the one FLL actually has authored anchors for. project_competencies still
 * reflects real FLL role membership (roles.json lists all five as valid FLL
 * competencies); only the demo's SCORED subset changed.
 */
final class DemoDataset
{
    /**
     * @return list<array{
     *   key: string, slug: string, name: string, assessment_type: string,
     *   role_code: string|null, language: string, status: string,
     *   pause_every_n_competencies: int, nudge_min_chars: int,
     *   competencies: list<string>
     * }>
     */
    public static function projects(): array
    {
        return [
            [
                'key' => 'P1',
                'slug' => DemoMarker::PREFIX.'sales-ico',
                'name' => 'Demo — Sales (ICO)',
                'assessment_type' => 'standard',
                'role_code' => 'ICO',
                'language' => 'it',
                'status' => 'active',
                'pause_every_n_competencies' => 3,
                'nudge_min_chars' => 120,
                'competencies' => ['PRS', 'STG', 'DRV', 'COM', 'COL'],
            ],
            [
                'key' => 'P2',
                'slug' => DemoMarker::PREFIX.'team-lead-fll',
                'name' => 'Demo — Team Lead (FLL)',
                'assessment_type' => 'standard',
                'role_code' => 'FLL',
                'language' => 'en',
                'status' => 'active',
                'pause_every_n_competencies' => 2,
                'nudge_min_chars' => 120,
                'competencies' => ['STG', 'INN', 'CSF', 'OPX', 'INS'],
            ],
            [
                'key' => 'P3',
                'slug' => DemoMarker::PREFIX.'mid-leader-mll',
                'name' => 'Demo — Mid Leader (MLL, draft)',
                'assessment_type' => 'standard',
                'role_code' => 'MLL',
                'language' => 'it',
                'status' => 'draft',
                'pause_every_n_competencies' => 3,
                'nudge_min_chars' => 120,
                // No participants are seeded under this project (a draft
                // project must not be reachable by a candidate), so these
                // three competencies need no BARS coverage — they only need
                // to be real MLL role competencies (roles.json confirms
                // PRS/STG/DRV are all assigned to MLL).
                'competencies' => ['PRS', 'STG', 'DRV'],
            ],
            [
                'key' => 'P4',
                'slug' => DemoMarker::PREFIX.'closed-campaign',
                'name' => 'Demo — Closed Campaign (ICO, archived)',
                'assessment_type' => 'standard',
                'role_code' => 'ICO',
                'language' => 'it',
                'status' => 'archived',
                'pause_every_n_competencies' => 3,
                'nudge_min_chars' => 120,
                'competencies' => ['PRS', 'STG', 'DRV', 'COM', 'COL'],
            ],
        ];
    }

    /**
     * @return list<array{
     *   key: string, ref: string, name: string, project_key: string,
     *   status: string,
     *   sessions: list<array{competency: string, status: string, ended_reason: ?string, live: bool}>,
     *   evaluation: ?array{status: string, scored: bool},
     *   scores: array<string, list<int>>,
     *   proctoring: ?array{competency: string, events: list<array{kind: string, payload: array<string, mixed>}>}
     * }>
     */
    public static function participants(): array
    {
        return [
            [
                'key' => 'c-001',
                'ref' => DemoMarker::PREFIX.'c-001',
                'name' => 'Giulia Ferrari',
                'project_key' => 'P1',
                'status' => 'completato',
                'sessions' => self::completedSessions(['PRS', 'STG', 'DRV', 'COM', 'COL']),
                'evaluation' => ['status' => 'completed', 'scored' => true],
                'scores' => [
                    'PRS' => [5, 3, 3],
                    'STG' => [3, 3, 1],
                    'DRV' => [5, 5, 3],
                    'COM' => [3, 5, 5],
                    'COL' => [5, 3, -1],
                ],
                'proctoring' => ['competency' => 'PRS', 'events' => self::c001Events()],
            ],
            [
                'key' => 'c-002',
                'ref' => DemoMarker::PREFIX.'c-002',
                'name' => 'Marco Bianchi',
                'project_key' => 'P1',
                'status' => 'completato',
                'sessions' => self::completedSessions(['PRS', 'STG', 'DRV', 'COM', 'COL']),
                'evaluation' => ['status' => 'pending', 'scored' => true],
                'scores' => [
                    'PRS' => [3, 3, 1],
                    'STG' => [5, -1, -1],
                    'DRV' => [3, 3, 3],
                    'COM' => [1, 3, 1],
                    'COL' => [-1, -1, -1],
                ],
                'proctoring' => null,
            ],
            [
                'key' => 'c-003',
                'ref' => DemoMarker::PREFIX.'c-003',
                'name' => 'Sara Colombo',
                'project_key' => 'P1',
                'status' => 'in_valutazione',
                'sessions' => self::completedSessions(['PRS', 'STG', 'DRV', 'COM', 'COL']),
                // processing, zero results — the transcript exists but scoring
                // has not finished. No `scores` entry: DemoWriter must not
                // write any CompetencyResult for this participant.
                'evaluation' => ['status' => 'processing', 'scored' => false],
                'scores' => [],
                'proctoring' => ['competency' => 'PRS', 'events' => self::c003Events()],
            ],
            [
                'key' => 'c-004',
                'ref' => DemoMarker::PREFIX.'c-004',
                'name' => 'Luca Moretti',
                'project_key' => 'P1',
                'status' => 'in_corso',
                'sessions' => [
                    ...self::completedSessions(['PRS', 'STG']),
                    ['competency' => 'DRV', 'status' => 'in_corso', 'ended_reason' => null, 'live' => true],
                ],
                'evaluation' => null,
                'scores' => [],
                'proctoring' => ['competency' => 'PRS', 'events' => self::c004Events()],
            ],
            [
                'key' => 'c-005',
                'ref' => DemoMarker::PREFIX.'c-005',
                'name' => 'Elena Ricci',
                'project_key' => 'P1',
                'status' => 'errore',
                'sessions' => [
                    ['competency' => 'PRS', 'status' => 'error', 'ended_reason' => 'error', 'live' => false],
                ],
                'evaluation' => null,
                'scores' => [],
                'proctoring' => null,
            ],
            [
                'key' => 'c-006',
                'ref' => DemoMarker::PREFIX.'c-006',
                'name' => 'Paolo Greco',
                'project_key' => 'P1',
                'status' => 'in_attesa',
                'sessions' => [],
                'evaluation' => null,
                'scores' => [],
                'proctoring' => null,
            ],
            [
                'key' => 'c-007',
                'ref' => DemoMarker::PREFIX.'c-007',
                'name' => 'Tom Bright',
                'project_key' => 'P2',
                'status' => 'completato',
                'sessions' => self::completedSessions(['STG', 'INN', 'CSF', 'OPX', 'INS']),
                'evaluation' => ['status' => 'completed', 'scored' => true],
                'scores' => [
                    'STG' => [5, 5, 5],
                    'INN' => [5, 3, 5],
                    'CSF' => [3, 3, 5],
                    'OPX' => [5, 5, 3],
                    'INS' => [5, 5, -1],
                ],
                'proctoring' => ['competency' => 'STG', 'events' => self::c007Events()],
            ],
            [
                'key' => 'c-008',
                'ref' => DemoMarker::PREFIX.'c-008',
                'name' => 'Anna Novak',
                'project_key' => 'P2',
                'status' => 'in_attesa',
                'sessions' => [],
                'evaluation' => null,
                'scores' => [],
                'proctoring' => null,
            ],
            [
                'key' => 'c-009',
                'ref' => DemoMarker::PREFIX.'c-009',
                'name' => 'Chiara Rossi',
                'project_key' => 'P4',
                'status' => 'completato',
                'sessions' => self::completedSessions(['PRS', 'STG', 'DRV', 'COM', 'COL']),
                'evaluation' => ['status' => 'completed', 'scored' => true],
                'scores' => [
                    'PRS' => [1, 1, 3],
                    'STG' => [1, 3, 1],
                    'DRV' => [3, 1, 1],
                    'COM' => [1, 1, 1],
                    'COL' => [1, -1, 1],
                ],
                'proctoring' => null,
            ],
        ];
    }

    /**
     * @param  list<string>  $competencyCodes
     * @return list<array{competency: string, status: string, ended_reason: ?string, live: bool}>
     */
    private static function completedSessions(array $competencyCodes): array
    {
        return array_map(
            static fn (string $code): array => ['competency' => $code, 'status' => 'completed', 'ended_reason' => 'completed', 'live' => false],
            $competencyCodes,
        );
    }

    /**
     * Snapshots: 2 per completed session for c-001, c-003, c-004, c-007
     * (design volume table) — 30 objects total (4 participants × 5 sessions
     * with `live=false` and a `completed`/`processing`-eligible status × ...
     * actually enumerated explicitly by DemoWriter per participant key,
     * this list is the authoritative membership set.
     *
     * @return list<string>
     */
    public static function snapshotParticipantKeys(): array
    {
        return ['c-001', 'c-003', 'c-004', 'c-007'];
    }

    /**
     * Per-language, per-competency, per-indicator-position candidate answer
     * content. Each answer is 2-3 sentences; `excerpt_sentence` is the
     * 0-based sentence index the stored IndicatorScore excerpt is sliced
     * from — always a real sentence of THAT answer, never invented.
     *
     * @return array<string, array<string, list<array{text: string, excerpt_sentence: int}>>>
     */
    public static function competencyContent(): array
    {
        return [
            'it' => [
                'PRS' => [
                    ['text' => 'Nel mio ultimo progetto ho notato i primi segnali di un problema di consegna e li ho segnalati subito al team. Ho raccolto i dati necessari prima di proporre una soluzione.', 'excerpt_sentence' => 0],
                    ['text' => 'Ho formulato tre ipotesi di soluzione e le ho testate una per una confrontandole con i dati già disponibili. La seconda ipotesi si è rivelata quella corretta.', 'excerpt_sentence' => 0],
                    ['text' => 'Quando le informazioni disponibili non bastavano, ho chiesto un confronto diretto con il cliente per capire la causa reale del problema.', 'excerpt_sentence' => 0],
                ],
                'STG' => [
                    ['text' => "Ho collegato l'obiettivo del mio team con la strategia complessiva dell'azienda, spiegando come le nostre scelte quotidiane influenzassero il risultato annuale.", 'excerpt_sentence' => 0],
                    ['text' => "Ho bilanciato una richiesta urgente con un obiettivo di lungo periodo, scegliendo di rimandare un'attività secondaria per non compromettere la strategia.", 'excerpt_sentence' => 0],
                    ['text' => "Ho valutato l'impatto di una decisione su altri reparti prima di procedere, coinvolgendo i colleghi interessati nella discussione.", 'excerpt_sentence' => 0],
                ],
                'DRV' => [
                    ['text' => 'Mi sono posto un obiettivo ambizioso per il trimestre e ho superato il target previsto lavorando con costanza ogni settimana.', 'excerpt_sentence' => 0],
                    ['text' => 'Di fronte a un ostacolo imprevisto ho continuato a lavorare sul problema fino a trovare una soluzione, senza abbassare gli standard che mi ero dato.', 'excerpt_sentence' => 0],
                    ['text' => "Ho mantenuto un ritmo di lavoro elevato per tutto il progetto, restando concentrato sull'obiettivo finale dell'azienda.", 'excerpt_sentence' => 0],
                ],
                'COM' => [
                    ['text' => 'Ho spiegato una decisione complessa agli stakeholder con un breve documento scritto, così che tutti potessero seguirla facilmente.', 'excerpt_sentence' => 0],
                    ['text' => 'Durante una riunione ho ascoltato con attenzione le obiezioni dei colleghi e ho adattato la mia proposta per tenerne conto.', 'excerpt_sentence' => 0],
                    ['text' => 'Ho tenuto informato il team con aggiornamenti brevi e regolari, evitando che le informazioni si perdessero tra i reparti.', 'excerpt_sentence' => 0],
                ],
                'COL' => [
                    ['text' => 'Quando un collega era in difficoltà ho riorganizzato le mie priorità per aiutarlo a completare il suo compito in tempo.', 'excerpt_sentence' => 0],
                    ['text' => 'Ho coinvolto attivamente i colleghi nella definizione del piano, così che le decisioni riflettessero il contributo di tutti.', 'excerpt_sentence' => 0],
                    ['text' => 'Ho distribuito il carico di lavoro del team in modo equo, tenendo conto delle competenze di ciascuno.', 'excerpt_sentence' => 0],
                ],
            ],
            'en' => [
                'STG' => [
                    ['text' => "I connected my team's quarterly goal to the company's overall strategy and explained how our daily choices affected the annual result.", 'excerpt_sentence' => 0],
                    ['text' => 'I balanced an urgent request against a long-term objective by deliberately postponing a secondary task.', 'excerpt_sentence' => 0],
                    ['text' => 'I evaluated the impact of a decision on other departments before proceeding, and involved the affected colleagues in the discussion.', 'excerpt_sentence' => 0],
                ],
                'INN' => [
                    ['text' => 'I approached a recurring complaint with curiosity and proposed a new process that removed the root cause instead of patching the symptom.', 'excerpt_sentence' => 0],
                    ['text' => 'I challenged the traditional way our team ran weekly reviews and introduced a shorter format that the team adopted permanently.', 'excerpt_sentence' => 0],
                    ['text' => 'I supported a colleague who wanted to try a risky new approach by covering part of their workload while they tested it.', 'excerpt_sentence' => 0],
                ],
                'CSF' => [
                    ['text' => "I understood a customer's real requirement behind their request and delivered something that matched it rather than the literal ask.", 'excerpt_sentence' => 0],
                    ['text' => "I followed up after delivery to confirm the customer's expectations were met, and adjusted the next milestone based on their feedback.", 'excerpt_sentence' => 0],
                    ['text' => 'I actively asked a customer for input mid-project instead of waiting for a scheduled review.', 'excerpt_sentence' => 0],
                ],
                'OPX' => [
                    ['text' => 'I broke a vague initiative into concrete action steps, assigned clear owners and set deadlines for each one.', 'excerpt_sentence' => 0],
                    ['text' => 'I built a resource estimate based on actual past data rather than optimistic assumptions, and it held throughout the project.', 'excerpt_sentence' => 0],
                    ['text' => "I used the team's tracking data to spot a recurring delay pattern and proposed a standard checklist to prevent it.", 'excerpt_sentence' => 0],
                ],
                'INS' => [
                    ['text' => 'I gave a colleague specific, immediate feedback that helped them fix a recurring mistake before it affected the client.', 'excerpt_sentence' => 0],
                    ['text' => "I publicly acknowledged a teammate's contribution in a meeting where it would otherwise have gone unnoticed.", 'excerpt_sentence' => 0],
                    ['text' => 'I helped a new team member understand which of their strengths mattered most for the role, and built a short development plan with them.', 'excerpt_sentence' => 0],
                ],
            ],
        ];
    }

    /**
     * The interviewer's line, repeated per turn. Never quoted as an excerpt
     * (only candidate utterances are), so it needs no per-turn variety.
     */
    public static function avatarPrompt(string $language): string
    {
        return $language === 'it'
            ? 'Può descrivere un episodio concreto relativo a questo aspetto del suo lavoro?'
            : 'Can you describe a concrete episode related to this aspect of your work?';
    }

    /**
     * @return list<array{kind: string, payload: array<string, mixed>}>
     */
    private static function c001Events(): array
    {
        return [
            ['kind' => 'looking_away', 'payload' => ['durationMs' => 4000]],
            ['kind' => 'looking_away', 'payload' => ['durationMs' => 4000]],
            ['kind' => 'looking_away', 'payload' => ['durationMs' => 4000]],
            ['kind' => 'focus_lost', 'payload' => []],
            ['kind' => 'looking_down', 'payload' => []],
            ['kind' => 'looking_down', 'payload' => []],
        ];
    }

    /**
     * @return list<array{kind: string, payload: array<string, mixed>}>
     */
    private static function c004Events(): array
    {
        return [
            ['kind' => 'tab_hidden', 'payload' => ['durationMs' => 12000]],
            ['kind' => 'focus_lost', 'payload' => []],
            ['kind' => 'face_absent', 'payload' => ['durationMs' => 8000]],
        ];
    }

    /**
     * @return list<array{kind: string, payload: array<string, mixed>}>
     */
    private static function c003Events(): array
    {
        return [
            ['kind' => 'second_monitor', 'payload' => ['isExtended' => true]],
            ['kind' => 'fullscreen_exit', 'payload' => []],
            ['kind' => 'fullscreen_exit', 'payload' => []],
            ['kind' => 'clipboard_paste', 'payload' => []],
            ['kind' => 'clipboard_copy', 'payload' => []],
            ['kind' => 'multiple_faces', 'payload' => ['durationMs' => 3000]],
            ['kind' => 'second_voice', 'payload' => ['durationMs' => 2000]],
        ];
    }

    /**
     * @return list<array{kind: string, payload: array<string, mixed>}>
     */
    private static function c007Events(): array
    {
        return [
            ['kind' => 'looking_away', 'payload' => ['durationMs' => 5000]],
        ];
    }

    /**
     * Env-overridable avatar/voice identifiers, read through
     * `config('interview.demo.*')` — never `env()` directly outside a config
     * file (larastan `noEnvCallsOutsideOfConfig`: `env()` returns null once
     * config is cached in production). Falls back to the committed values
     * named in the proposal (real, working resource ids — not the
     * placeholder `demo_*` strings the legacy DemoSeeder used).
     *
     * @return array{
     *   heygen: array{avatarId: string, voiceId: string, language: string},
     *   tavus: array{faceId: string, palId: string}
     * }
     */
    public static function avatarIdentity(): array
    {
        return [
            'heygen' => [
                'avatarId' => (string) config('interview.demo.heygen.avatar_id'),
                'voiceId' => (string) config('interview.demo.heygen.voice_id'),
                'language' => (string) config('interview.demo.heygen.language'),
            ],
            'tavus' => [
                'faceId' => (string) config('interview.demo.tavus.replica_id'),
                'palId' => (string) config('interview.demo.tavus.persona_id'),
            ],
        ];
    }
}
