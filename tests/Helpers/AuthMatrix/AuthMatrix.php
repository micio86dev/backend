<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

/**
 * Vocabulary of the authorization matrix: auth kinds, tenancy kinds, actors
 * and expected outcomes. Pure constants — the per-route expectations live in
 * {@see AuthMatrixCatalogue}.
 *
 * ACTORS
 * ------
 * An actor is a CREDENTIAL plus, for user JWTs, a position relative to the
 * organization that owns the target resource. The per-actor request tests
 * (later tasks) build each one with the existing fixtures:
 *
 *   admin / operator / viewer  authTokenForRole('<role>') on the target's org
 *   no_role                    a user of the target's org with no Spatie role
 *   cross_tenant_admin         an ADMIN of a DIFFERENT org than the target's
 *   superadmin_bare            saSuperadmin() with no acting organization
 *   superadmin_acting          saSuperadmin() acting as the target's org
 *                              (authTokenForRole('platform'))
 *
 * OUTCOMES
 * --------
 * An outcome is what the actor must get for a request that is otherwise
 * VALID: the target exists (and belongs to the actor's org, except for
 * `cross_tenant_admin`) and the payload passes validation. So `ALLOW` means
 * a 2xx, never "an authorization pass followed by a 422".
 */
final class AuthMatrix
{
    // ─── Auth kinds: which credential a route demands ────────────────────
    public const AUTH_PUBLIC = 'public';

    public const AUTH_JWT_USER = 'jwt-user';

    public const AUTH_CANDIDATE = 'candidate';

    public const AUTH_M2M = 'm2m';

    public const AUTH_PUBLIC_API_KEY = 'public-api-key';

    public const AUTH_KINDS = [
        self::AUTH_PUBLIC,
        self::AUTH_JWT_USER,
        self::AUTH_CANDIDATE,
        self::AUTH_M2M,
        self::AUTH_PUBLIC_API_KEY,
    ];

    // ─── Tenancy kinds: how the route decides whose data it touches ──────
    /** Rows are scoped to the caller's organization (TenantContext). */
    public const TENANCY_ORG_SCOPED = 'org-scoped';

    /** Platform surface: only a superadmin may call it. */
    public const TENANCY_SUPERADMIN_ONLY = 'superadmin-only';

    /** The credential itself names the tenant (own profile, candidate, API key). */
    public const TENANCY_SELF = 'self';

    /** No tenant involved (health, login, public assets). */
    public const TENANCY_NONE = 'none';

    public const TENANCY_KINDS = [
        self::TENANCY_ORG_SCOPED,
        self::TENANCY_SUPERADMIN_ONLY,
        self::TENANCY_SELF,
        self::TENANCY_NONE,
    ];

    // ─── Actors ──────────────────────────────────────────────────────────
    public const UNAUTHENTICATED = 'unauthenticated';

    public const ADMIN = 'admin';

    public const OPERATOR = 'operator';

    public const VIEWER = 'viewer';

    public const NO_ROLE = 'no_role';

    public const CROSS_TENANT_ADMIN = 'cross_tenant_admin';

    public const SUPERADMIN_BARE = 'superadmin_bare';

    public const SUPERADMIN_ACTING = 'superadmin_acting';

    /** A candidate JWT (typ=candidate) presented to a route of another kind. */
    public const CANDIDATE_JWT = 'candidate_jwt';

    /** A live M2M / Public API key presented to a user-JWT route. */
    public const API_KEY = 'api_key';

    /** An organization user's JWT presented to a candidate/M2M/API-key route. */
    public const USER_JWT = 'user_jwt';

    public const CANDIDATE_ACTIVE = 'candidate_active';

    /** Participant already `completato`/`errore` (ParticipantStatusGuard). */
    public const CANDIDATE_TERMINAL = 'candidate_terminal';

    public const KEY_WITH_ABILITY = 'key_with_ability';

    public const KEY_WITHOUT_ABILITY = 'key_without_ability';

    public const KEY_WITH_SCOPE = 'key_with_scope';

    public const KEY_WITHOUT_SCOPE = 'key_without_scope';

    /** `beai_test_` key: valid on `/v1`, refused by the internal M2M guard. */
    public const TEST_MODE_KEY = 'test_mode_key';

    /** Live key sent with a browser `Origin` header (refused on `/v1`). */
    public const LIVE_KEY_BROWSER_ORIGIN = 'live_key_browser_origin';

    public const ANONYMOUS = 'anonymous';

    /** @var array<string, list<string>> actors an entry must give an outcome for, per auth kind */
    public const ACTORS_BY_AUTH = [
        self::AUTH_PUBLIC => [self::ANONYMOUS],
        self::AUTH_JWT_USER => [
            self::UNAUTHENTICATED,
            self::ADMIN,
            self::OPERATOR,
            self::VIEWER,
            self::NO_ROLE,
            self::CROSS_TENANT_ADMIN,
            self::SUPERADMIN_BARE,
            self::SUPERADMIN_ACTING,
            self::CANDIDATE_JWT,
            self::API_KEY,
        ],
        self::AUTH_CANDIDATE => [
            self::UNAUTHENTICATED,
            self::CANDIDATE_ACTIVE,
            self::CANDIDATE_TERMINAL,
            self::USER_JWT,
            self::API_KEY,
        ],
        self::AUTH_M2M => [
            self::UNAUTHENTICATED,
            self::KEY_WITH_ABILITY,
            self::KEY_WITHOUT_ABILITY,
            self::TEST_MODE_KEY,
            self::USER_JWT,
            self::CANDIDATE_JWT,
        ],
        self::AUTH_PUBLIC_API_KEY => [
            self::UNAUTHENTICATED,
            self::KEY_WITH_SCOPE,
            self::KEY_WITHOUT_SCOPE,
            self::TEST_MODE_KEY,
            self::LIVE_KEY_BROWSER_ORIGIN,
            self::USER_JWT,
            self::CANDIDATE_JWT,
        ],
    ];

    // ─── Outcomes ────────────────────────────────────────────────────────
    /** 2xx — the request succeeds. */
    public const ALLOW = '2xx';

    public const UNAUTHENTICATED_401 = '401';

    public const FORBIDDEN = '403';

    /** The request conflicts with the caller's state (e.g. no acting client selected). */
    public const CONFLICT = '409';

    /** Cross-tenant / unknown id: existence is never confirmed. */
    public const NOT_FOUND = '404';

    /**
     * Reachable without credentials: the guard never answers 401/403, the
     * status then depends on the payload/token (login → 422, sso → 302 …).
     */
    public const OPEN = 'open';

    /**
     * The code's behaviour looks like a bug or is ambiguous, so the matrix
     * refuses to encode it. Every such cell is listed in
     * {@see AuthMatrixCatalogue::knownQuestions()}.
     */
    public const UNRESOLVED = 'unresolved';

    public const OUTCOMES = [
        self::ALLOW,
        self::UNAUTHENTICATED_401,
        self::FORBIDDEN,
        self::CONFLICT,
        self::NOT_FOUND,
        self::OPEN,
        self::UNRESOLVED,
    ];
}
