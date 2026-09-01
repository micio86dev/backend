<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\AvatarTemplate;
use App\Models\LlmCredential;
use App\Models\Organization;
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
     * @return array<string, array<string, bool>>
     */
    public function for(User $user): array
    {
        // A brand-new organization has no rows yet, and `Gate` still needs a
        // subject for the model-instance policies. An unsaved instance carries
        // the org id, which is all `OrganizationPolicy` reads.
        $organization = Organization::find($user->organization_id)
            ?? new Organization(['id' => $user->organization_id]);

        $gate = Gate::forUser($user);

        return [
            'organization' => [
                'view' => $gate->allows('view', $organization),
                'update' => $gate->allows('update', $organization),
            ],
            'users' => [
                'viewAny' => $gate->allows('viewAny', User::class),
                'create' => $gate->allows('create', User::class),
            ],
            'llmCredentials' => [
                'viewAny' => $gate->allows('viewAny', LlmCredential::class),
                'create' => $gate->allows('create', LlmCredential::class),
            ],
            'avatarTemplates' => [
                'viewAny' => $gate->allows('viewAny', AvatarTemplate::class),
                'create' => $gate->allows('create', AvatarTemplate::class),
            ],
            'projects' => [
                'viewAny' => $gate->allows('viewAny', Project::class),
                'create' => $gate->allows('create', Project::class),
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
