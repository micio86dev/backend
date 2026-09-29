<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AvatarTemplates\DuplicateAvatarTemplate;
use App\Models\AvatarTemplate;
use App\Support\AvatarTemplates\ConfigValidator;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Copy an avatar template into other organizations (superadmin only).
 *
 * Gated by the SAME ability as authoring a template (`create`), checked BEFORE
 * the template is looked up: every non-superadmin gets a flat 403, so the
 * route is no existence oracle. It needs no `org.context` — the source is read
 * through the ordinary tenant scope (a bare superadmin sees every tenant, an
 * acting one sees the organization they act as) and each write establishes its
 * own target context.
 */
final class AvatarTemplateDuplicateController extends Controller
{
    public function __construct(private readonly DuplicateAvatarTemplate $duplicate) {}

    /**
     * POST /api/avatar-templates/{id}/duplicate
     *
     * Creates one new INACTIVE template per target organization and answers
     * `{data: [{organization_id, id, name}]}` in the order of the targets.
     * All-or-nothing. The source organization among the targets is a 422
     * (`source_organization_included`).
     */
    #[ResponseDoc(201, type: 'array{data: list<array{organization_id: int, id: int, name: string}>}')]
    public function __invoke(Request $request, int $id): JsonResponse
    {
        $this->authorize('create', AvatarTemplate::class);

        $validated = $request->validate([
            'target_organization_ids' => ['required', 'array', 'min:1'],
            'target_organization_ids.*' => ['required', 'integer', 'distinct', Rule::exists('organizations', 'id')],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $template = AvatarTemplate::findOrFail($id);

        /** @var list<int> $targets */
        $targets = array_map('intval', $validated['target_organization_ids']);

        if (in_array($template->organization_id, $targets, true)) {
            throw ValidationException::withMessages([
                'target_organization_ids' => 'source_organization_included',
            ]);
        }

        // A copy of a template that no longer validates would just move the
        // problem into another organization.
        if (ConfigValidator::validate($template->provider, $template->config) !== []) {
            throw ValidationException::withMessages(['template' => 'source_config_invalid']);
        }

        $created = $this->duplicate->run($template, $targets, $validated['name'] ?? null);

        return response()->json(['data' => $created], Response::HTTP_CREATED);
    }
}
