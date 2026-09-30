<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformAvatarTemplateResource;
use App\Models\AvatarTemplate;
use App\Models\User;
use App\Support\AvatarTemplates\GlobalAvatarTemplateUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform (global) avatar templates: rows with no organization, offered to
 * every organization for new project pins (global-avatar-templates D4).
 *
 * Routes (auth:api + TenantContext, superadmin-only, NO `org.context`):
 *   GET  /admin/avatar-templates
 *   GET  /admin/avatar-templates/{id}
 *
 * Every lookup is `AvatarTemplate::platformOnly()`, so an organization's
 * template id is a plain 404 here, exactly as a platform id is a 404 on every
 * organization route. The caller is a superadmin either way — bare, or acting
 * as an organization — and the acting organization is never consulted.
 *
 * The `abort_unless` is written out at every action rather than hidden in a
 * helper, for the reason `PlatformUserController` records: Scramble documents
 * only the refusals a controller visibly makes.
 */
final class PlatformAvatarTemplateController extends Controller
{
    public function __construct(private readonly GlobalAvatarTemplateUsage $usage) {}

    private function isSuperadmin(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->is_superadmin === true;
    }

    /**
     * @response array{data: list<\App\Http\Resources\PlatformAvatarTemplateResource>}
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $templates = AvatarTemplate::platformOnly()->orderByDesc('is_active')->orderBy('name')->get();
        $usage = $this->usage->for(array_values(array_map(intval(...), $templates->modelKeys())));

        return new AnonymousResourceCollection(
            $templates->map(fn (AvatarTemplate $template): PlatformAvatarTemplateResource => new PlatformAvatarTemplateResource($template, $usage[$template->id])),
            PlatformAvatarTemplateResource::class,
        );
    }

    public function show(Request $request, int $id): PlatformAvatarTemplateResource
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        return $this->present(AvatarTemplate::platformOnly()->findOrFail($id));
    }

    private function present(AvatarTemplate $template): PlatformAvatarTemplateResource
    {
        return new PlatformAvatarTemplateResource($template, $this->usage->for([$template->id])[$template->id]);
    }
}
