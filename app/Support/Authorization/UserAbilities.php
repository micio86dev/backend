<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Gate;

/**
 * What the authenticated user may do, resolved BY THE POLICIES themselves.
 *
 * The backoffice has to know this. A page an operator cannot use should not be
 * in their navigation, and a button that will always come back 403 should not
 * be on their screen — being told "forbidden" after clicking is a worse product
 * than never being offered the action.
 *
 * The obvious way to build that is to check the role in the client:
 * `v-if="roles.includes('admin')"`. That is what this class exists to prevent.
 * It puts a SECOND copy of every authorization rule in a second language in a
 * second repository, and the two drift the moment one changes — usually
 * silently, and usually in the permissive direction, because a UI that shows
 * too much looks fine until someone clicks.
 *
 * So the server answers the question it already knows the answer to. Every
 * value below is a real `Gate::forUser()` call against the SAME policy that
 * guards the endpoint. Narrowing a policy therefore removes the button on the
 * next page load, with no client-side change and no possibility of drift.
 *
 * THIS IS NOT THE ENFORCEMENT POINT AND MUST NEVER BECOME ONE. It is a hint
 * for rendering. Every endpoint keeps authorizing independently, because a
 * client is free to ignore anything it is told, and a hidden button is not a
 * closed door.
 */
final class UserAbilities
{
    public function __construct(private readonly TenantResolver $resolver) {}

    /**
     * Nested rather than dotted-flat (`['organization.update' => true]`).
     * A dot in a key is ambiguous everywhere it is later read — Laravel's own
     * `data_get`, `assertJsonPath`, and any client doing lookups by path all
     * read it as a level of nesting that is not there — so the structure says
     * what it means instead.
     *
     * EVERY ability a CTA is gated on lives here, not just the ones that open
     * a page. A map that stopped at `viewAny`/`create` could gate navigation
     * and nothing else, so each list's row actions — edit, delete, activate —
     * were left ungated or, worse, gated on a role string parsed in the
     * client. Both shipped. An admin saw Edit and Deactivate on avatar
     * templates they may not manage, and `projects`/`participants` re-derived
     * `canInvite`/`isViewer` from `profile.data.role`.
     *
     * WHY THESE ARE CLASS-LEVEL AND NOT PER-ROW `can` OBJECTS. Not one of the
     * mutating policies below varies by RECORD: they read the actor's role and
     * nothing else.
     *
     * `ApiClientPolicy::delete` is the one that looks like an exception, since
     * it compares organization ids — and `ApiClient` is deliberately NOT a
     * `TenantModel` (see `tests/Arch/C2/TenantModelArchTest.php`, which
     * excludes it by name: the api-m2m guard has to query it unscoped, before
     * any tenant context exists). What makes the class-level answer safe there
     * is not a global scope but an EXPLICIT filter —
     * `M2m/ApiClientController::index()` lists with
     * `ApiClient::where('organization_id', $resolver->getOrgId())`. If that
     * filter ever goes, this answer goes with it.
     *
     * That filter reads the RESOLVER, and the `$apiClient` subject built below
     * is stamped from `$user->organization_id`. The two are the same value for
     * every identity except one — a superadmin acting as a client carries a
     * null column and a non-null resolver — and for that one `Gate::before`
     * answers true before any policy runs, so the subject is never consulted.
     * Spelled out because the two sources previously WERE the same expression,
     * and a future reader comparing them would otherwise find a discrepancy
     * with no note saying it was looked at.
     *
     * Answering once per request is therefore the same answer as answering
     * once per row, minus N gate calls per response and minus a `can` object
     * on every item of every list in the OpenAPI contract. `forModel()` below
     * stays for the day an ability genuinely does depend on the record.
     *
     * WHAT THIS MAP DELIBERATELY DOES NOT ANSWER: the last-admin and
     * self-deactivation invariants. Those live in `UserGuards`, not in
     * `UserPolicy`, and they are not affordance questions — "you cannot
     * deactivate the last admin" is something an operator must be TOLD, and a
     * button that silently vanishes teaches nothing. The button renders; the
     * API explains.
     *
     * WHICH ORGANIZATION THIS ANSWERS FOR. The caller's own when they have
     * one, otherwise the ACTING organization `TenantContext` resolved for a
     * superadmin. A superadmin's own column is null by definition, so reading
     * only the column answered for nobody: the subjects below were built
     * org-less and a superadmin acting as a client was told what a user with
     * no organization is told.
     *
     * WITH NO ORGANIZATION AT ALL (a superadmin who has selected no client)
     * four groups are published as all-`false`: `organization`, `apiClients`,
     * `projects` and `participants`. They describe one client's data, every
     * write behind them is refused with 409 `organization_context_required`,
     * and `Gate::before` would otherwise answer `true` for all of them. The
     * suppression has to live here for that reason: no policy body runs for a
     * superadmin, so no policy can say "nothing is selected".
     *
     * The other groups are NOT suppressed, and the omission is deliberate:
     * `users.viewAny` is the ability that guards `/settings`, which with no
     * client selected hosts the PLATFORM user list, the platform credentials
     * and the platform settings; `llmCredentials` are platform rows;
     * `avatarTemplates.*` guard two `scope: 'platform'` pages. Hiding those
     * would lock the superadmin out of the screens used to pick a client.
     *
     * @return array{
     *     organization: array{view: bool, update: bool},
     *     apiClients: array{viewAny: bool, create: bool, delete: bool},
     *     users: array{viewAny: bool, create: bool, update: bool, deactivate: bool, activate: bool},
     *     llmCredentials: array{viewAny: bool, create: bool, update: bool, delete: bool},
     *     avatarTemplates: array{viewAny: bool, create: bool, update: bool, activate: bool, delete: bool, manageGlobal: bool},
     *     projects: array{viewAny: bool, create: bool, update: bool, delete: bool},
     *     participants: array{viewAny: bool, create: bool, recover: bool, retry: bool},
     *     clients: array{viewAny: bool},
     *     platformSettings: array{viewAny: bool},
     *     catalogue: array{manage: bool},
     * }
     */
    public function for(User $user): array
    {
        // The organization in context: the caller's own, else the acting one.
        $orgId = $user->organization_id ?? $this->resolver->getOrgId();

        // A brand-new organization has no rows yet, and `Gate` still needs a
        // subject for the model-instance policies.
        //
        // The fallback instance is a PLACEHOLDER and nothing more. It does not
        // carry the org id — `id` is not in `Organization::$fillable`, so the
        // constructor array is dropped — and it does not need to:
        // `OrganizationPolicy::view()` and `::update()` read the actor's roles
        // and never touch the subject. The argument exists because Laravel
        // strips a class-string before calling a policy, so `allows('update',
        // Organization::class)` would invoke `update($user)` and die on the
        // missing parameter.
        $organization = ($orgId === null ? null : Organization::find($orgId))
            ?? new Organization;

        $gate = Gate::forUser($user);

        // The instance-typed abilities (`update`, `delete`, `activate`, …)
        // need a SUBJECT: Laravel strips a class-string argument before
        // calling the policy, so `allows('update', Project::class)` invokes
        // `update($user)` and dies on the missing second parameter. Each
        // policy reads the actor's role and — for ApiClient — the row's
        // organization, so an unsaved instance carrying this user's
        // organization is a faithful stand-in and touches no database.
        //
        // Spelled out per model rather than built by a `fn (string $class)`
        // helper. The helper version type-checked as `Model`, which knows
        // nothing about `organization_id` and turned a column assignment into
        // an undefined-property access — the concrete classes carry their own
        // schema, so this way the types are real rather than asserted.
        //
        // `organization_id` is assigned directly and never through the
        // constructor: it is excluded from `$fillable` on the tenant models by
        // design (mass-assigning the column that decides which tenant owns a
        // row is the hole `TenantScoped` closes), so a constructor array would
        // silently drop it and leave `ApiClientPolicy::delete` comparing
        // against null.
        $apiClient = new ApiClient;
        $targetUser = new User;
        $llmCredential = new LlmCredential;
        $avatarTemplate = new AvatarTemplate;
        $project = new Project;

        // Assigned only when there IS one. `users.organization_id` is nullable
        // and the tenant models' is not, and the gap is not an accident: the
        // user with no organization is the SUPERADMIN, and a tenant row with a
        // null owner is the state `TenantScoped` exists to make impossible.
        //
        // A superadmin acting as a client now gets them stamped with that
        // client, like a member of it. With no client the subjects stay
        // org-less, which is correct on the merits — `Gate::before` answers
        // every ability for a superadmin before a policy is consulted, so
        // nothing downstream ever reads the column on these instances.
        // `$llmCredential` is absent from this list on purpose. Credentials
        // became PLATFORM rows (RATIFIED 2026-09-14) and carry no
        // `organization_id` at all, so assigning one would be an undefined
        // property write. Its policy reads `is_superadmin` and never touches
        // the subject, so the bare instance is a faithful stand-in.
        if ($orgId !== null) {
            $apiClient->organization_id = $orgId;
            // `forceFill`, not a property write: the column is typed `int<0, max>` on `User` and the acting
            // organization comes from the resolver as a plain `int`. Same effect as the direct assignment the
            // other subjects use — `organization_id` is deliberately not fillable.
            $targetUser->forceFill(['organization_id' => $orgId]);
            $avatarTemplate->organization_id = $orgId;
            $project->organization_id = $orgId;
        }

        // Computed once: it decides four groups below, and they must agree.
        $hasOrganization = $orgId !== null;

        return [
            'organization' => [
                'view' => $hasOrganization && $gate->allows('view', $organization),
                'update' => $hasOrganization && $gate->allows('update', $organization),
            ],
            'apiClients' => [
                'viewAny' => $hasOrganization && $gate->allows('viewAny', ApiClient::class),
                'create' => $hasOrganization && $gate->allows('create', ApiClient::class),
                'delete' => $hasOrganization && $gate->allows('delete', $apiClient),
            ],
            'users' => [
                'viewAny' => $gate->allows('viewAny', User::class),
                'create' => $gate->allows('create', User::class),
                'update' => $gate->allows('update', $targetUser),
                'deactivate' => $gate->allows('deactivate', $targetUser),
                'activate' => $gate->allows('activate', $targetUser),
            ],
            'llmCredentials' => [
                'viewAny' => $gate->allows('viewAny', LlmCredential::class),
                'create' => $gate->allows('create', LlmCredential::class),
                'update' => $gate->allows('update', $llmCredential),
                'delete' => $gate->allows('delete', $llmCredential),
            ],
            'avatarTemplates' => [
                'viewAny' => $gate->allows('viewAny', AvatarTemplate::class),
                'create' => $gate->allows('create', AvatarTemplate::class),
                'update' => $gate->allows('update', $avatarTemplate),
                'activate' => $gate->allows('activate', $avatarTemplate),
                'delete' => $gate->allows('delete', $avatarTemplate),
                // No subject: platform templates belong to no organization.
                'manageGlobal' => $gate->allows('manageGlobalAvatarTemplates'),
            ],
            'projects' => [
                'viewAny' => $hasOrganization && $gate->allows('viewAny', Project::class),
                'create' => $hasOrganization && $gate->allows('create', Project::class),
                'update' => $hasOrganization && $gate->allows('update', $project),
                'delete' => $hasOrganization && $gate->allows('delete', $project),
            ],
            'participants' => [
                'viewAny' => $hasOrganization && $gate->allows('viewAny', Participant::class),
                'create' => $hasOrganization && $gate->allows('create', Participant::class),
                // No subject: `ParticipantPolicy::recover` takes the actor
                // alone, because recovery is a capability rather than a
                // judgement about one participant.
                'recover' => $hasOrganization && $gate->allows('recover', Participant::class),
                // No subject either: authorizing the one evaluation retry is a
                // capability of the role, decided before any participant is read.
                'retry' => $hasOrganization && $gate->allows('retry', Participant::class),
            ],
            // No subject, like `participants.recover` above: the ability is
            // about the caller (superadmin-clients-console D4), not a row —
            // there is no Client model to authorize against.
            'clients' => [
                'viewAny' => $gate->allows('viewAnyClients'),
            ],
            // Same shape, same reasoning: platform rows belong to BEAI rather
            // than to any organization, so no org-scoped policy can describe
            // who may edit them.
            'platformSettings' => [
                'viewAny' => $gate->allows('viewPlatformSettings'),
            ],
            // framework-catalogue-authoring PR3, D12 — same shape as
            // `clients`/`platformSettings` above: no subject, platform
            // content rather than a tenant's.
            'catalogue' => [
                'manage' => $gate->allows('manageCatalogue'),
            ],
        ];
    }

    /**
     * Per-record abilities cannot be answered by the map above: `delete` takes
     * the record, and a role that may delete one project may not be allowed to
     * delete another. Resources call this so each row carries its own answer
     * instead of the client re-deriving one from a role name.
     *
     * @param  array<int, string>  $abilities
     * @return array<string, bool>
     */
    public function forModel(User $user, object $model, array $abilities): array
    {
        $result = [];

        foreach ($abilities as $ability) {
            $result[$ability] = Gate::forUser($user)->allows($ability, $model);
        }

        return $result;
    }
}
