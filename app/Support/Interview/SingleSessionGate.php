<?php

declare(strict_types=1);

namespace App\Support\Interview;

use App\Models\Project;

/**
 * The only reader of the single-session flag and canary list
 * (tavus-single-session-interview, design N1).
 *
 * A project is in single-session mode when its resolved provider is `tavus`
 * and either the global flag is on or the project id is in the canary list.
 * HeyGen and mock never apply, whatever the flag says. The provider is passed
 * in because it is resolved per session (pinned provider, then
 * `provider_override`, then the env default), not from the project alone.
 */
final class SingleSessionGate
{
    public function applies(Project $project, string $provider): bool
    {
        if ($provider !== 'tavus') {
            return false;
        }

        if ((bool) config('interview.tavus.single_session', false)) {
            return true;
        }

        /** @var list<int|string> $canary */
        $canary = (array) config('interview.tavus.single_session_projects', []);

        return in_array($project->id, array_map('intval', $canary), true);
    }
}
