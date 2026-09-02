<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectQuestionRequest;
use App\Http\Resources\ProjectQuestionResource;
use App\Models\Project;
use App\Models\ProjectQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The predefined questions of one project
 * (potential-competencies-and-authored-questions).
 *
 * Nested under the project, and the project is resolved through the TENANT
 * SCOPE — `Project` is a TenantModel, so another organization's id simply does
 * not resolve and route-model binding 404s. That is deliberate rather than a
 * 403: a 403 would confirm the project exists, which is an existence oracle
 * across tenants, and it is the same doctrine the rest of this API follows.
 */
class ProjectQuestionController extends Controller
{
    /**
     * Resolve the project THROUGH the tenant scope.
     *
     * Route-model binding is deliberately not used, and this is the same
     * choice `ProjectController` already made: `SubstituteBindings` runs
     * before `TenantContext`, so a bound model would be fetched with no
     * organization established and the global scope would filter it out —
     * every request 404ing, including the caller's own project.
     *
     * Resolved here instead, after the middleware, where `Project::findOrFail`
     * is already scoped. Another organization's id therefore 404s rather than
     * 403s: a 403 would confirm the project exists, which is an existence
     * oracle across tenants.
     */
    private function resolveProject(int $projectId): Project
    {
        return Project::findOrFail($projectId);
    }

    public function index(int $project): AnonymousResourceCollection
    {
        $project = $this->resolveProject($project);
        $this->authorize('view', $project);

        return ProjectQuestionResource::collection(
            ProjectQuestion::with('competency')
                ->where('project_id', $project->id)
                ->orderBy('competency_id')
                ->orderBy('position')
                ->get()
        );
    }

    public function store(StoreProjectQuestionRequest $request, int $project): JsonResponse
    {
        $project = $this->resolveProject($project);
        $validated = $request->validated();

        // Appended at the end of ITS competency's list. Derived rather than
        // accepted from the client: a caller-supplied position would collide
        // with the partial unique index the moment two operators add a
        // question at once, and the answer they want is always "last".
        $position = (int) ProjectQuestion::where('project_id', $project->id)
            ->where('competency_id', $validated['competency_id'])
            ->max('position');

        $exists = ProjectQuestion::where('project_id', $project->id)
            ->where('competency_id', $validated['competency_id'])
            ->exists();

        $question = ProjectQuestion::create([
            'project_id' => $project->id,
            'competency_id' => $validated['competency_id'],
            'text' => $validated['text'],
            'position' => $exists ? $position + 1 : 0,
        ]);

        return ProjectQuestionResource::make($question->load('competency'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, int $project, int $questionId): ProjectQuestionResource
    {
        $project = $this->resolveProject($project);
        $this->authorize('update', $project);

        $question = ProjectQuestion::where('project_id', $project->id)->findOrFail($questionId);

        // Only the wording. The competency is NOT movable: a question written
        // to probe one competency is not a question about another, and
        // "moving" it would silently change what an interview measures.
        // Delete and re-author instead.
        $validated = $request->validate([
            'text' => ['required', 'array'],
            'text.en' => ['required', 'string', 'max:2000'],
            'text.it' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $question->update(['text' => $validated['text']]);

        return ProjectQuestionResource::make($question->load('competency'));
    }

    /**
     * Reorder, taking the WHOLE ordered list.
     *
     * Drag-and-drop knows the final order, and sending it in full is what
     * makes the partial unique index satisfiable: moving rows one at a time
     * would collide with the position each is moving into. Positions are
     * rewritten from scratch inside one transaction, so a failure halfway
     * leaves the previous order rather than a half-applied one.
     */
    public function reorder(Request $request, int $project): JsonResponse
    {
        $project = $this->resolveProject($project);
        $this->authorize('update', $project);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ]);

        /** @var list<int> $ids */
        $ids = array_map('intval', $validated['ids']);

        $questions = ProjectQuestion::where('project_id', $project->id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($questions->count() !== count($ids)) {
            // Every id must belong to THIS project. A partial match would
            // silently reorder a subset and leave the rest at stale
            // positions — worse than refusing, because it looks like it worked.
            return response()->json(
                ['message' => 'Every id must belong to this project.', 'code' => 'QUESTION_SET_MISMATCH'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Sorted into the caller's order rather than indexed by id. Reading
        // `$questions[$id]` would be nullable at the type level even though the
        // count check above rules it out, and a cast or a null-check there
        // would be noise asserting something already established. `flip` turns
        // the id list into a rank lookup, so this is one pass.
        $rank = array_flip($ids);

        /** @var list<ProjectQuestion> $ordered */
        $ordered = $questions
            ->sortBy(static fn (ProjectQuestion $q): int => $rank[$q->id])
            ->values()
            ->all();

        DB::transaction(function () use ($ordered): void {
            // Two passes, and the parking offset is not cosmetic: positions are
            // unique per (project, competency) among live rows, so writing the
            // new order directly would collide with a position still held by a
            // row not yet moved. Parking everything above the used range first
            // makes the second pass collision-free.
            $park = 1_000_000;

            foreach ($ordered as $i => $question) {
                $question->update(['position' => $park + $i]);
            }

            $perCompetency = [];

            foreach ($ordered as $question) {
                $key = (int) $question->competency_id;
                $perCompetency[$key] = ($perCompetency[$key] ?? -1) + 1;
                $question->update(['position' => $perCompetency[$key]]);
            }
        });

        return response()->json(['message' => 'Reordered.']);
    }

    public function destroy(int $project, int $questionId): Response
    {
        $project = $this->resolveProject($project);
        $this->authorize('update', $project);

        $question = ProjectQuestion::where('project_id', $project->id)->findOrFail($questionId);

        // Soft: interviews already conducted under this question still refer
        // to it, and a hard delete would leave those transcripts unexplainable.
        $question->delete();

        return response()->noContent();
    }
}
