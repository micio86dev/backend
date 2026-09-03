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
     * `M2m/ApiClientController` lists with
     * `ApiClient::where('organization_id', $user->organization_id)`. If that
     * filter ever goes, this answer goes with it.
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
     * @return array{
     *     organization: array{view: bool, update: bool},
     *     apiClients: array{viewAny: bool, create: bool, delete: bool},
     *     users: array{viewAny: bool, create: bool, update: bool, deactivate: bool, activate: bool},
     *     llmCredentials: array{viewAny: bool, create: bool, update: bool, delete: bool},
     *     avatarTemplates: array{viewAny: bool, create: bool, update: bool, activate: bool, delete: bool},
     *     projects: array{viewAny: bool, create: bool, update: bool, delete: bool},
     *     participants: array{viewAny: bool, create: bool, recover: bool},
     * }
     */
    public function for(User $user): array
    {
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
        $organization = Organization::find($user->organization_id)
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
        $orgId = $user->organization_id;

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
        // Leaving these subjects org-less for that identity is also correct on
        // the merits — `Gate::before` answers every ability for a superadmin
        // before a policy is consulted, so nothing downstream ever reads the
        // column on these instances.
        if ($orgId !== null) {
            $apiClient->organization_id = $orgId;
            $targetUser->organization_id = $orgId;
            $llmCredential->organization_id = $orgId;
            $avatarTemplate->organization_id = $orgId;
            $project->organization_id = $orgId;
        }

        return [
            'organization' => [
                'view' => $gate->allows('view', $organization),
                'update' => $gate->allows('update', $organization),
            ],
            'apiClients' => [
                'viewAny' => $gate->allows('viewAny', ApiClient::class),
                'create' => $gate->allows('create', ApiClient::class),
                'delete' => $gate->allows('delete', $apiClient),
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
            ],
            'projects' => [
                'viewAny' => $gate->allows('viewAny', Project::class),
                'create' => $gate->allows('create', Project::class),
                'update' => $gate->allows('update', $project),
                'delete' => $gate->allows('delete', $project),
            ],
            'participants' => [
                'viewAny' => $gate->allows('viewAny', Participant::class),
                'create' => $gate->allows('create', Participant::class),
                // No subject: `ParticipantPolicy::recover` takes the actor
                // alone, because recovery is a capability rather than a
                // judgement about one participant.
                'recover' => $gate->allows('recover', Participant::class),
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
