<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectQuestionRequest;
use App\Http\Requests\UpdateProjectQuestionRequest;
use App\Http\Resources\ProjectQuestionResource;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Support\Settings\PlatformSettings;
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

    /**
     * The cap travels WITH the list, in `meta`.
     *
     * `StoreProjectQuestionRequest` already refuses the (N+1)th question, but
     * the backoffice had no way to know N before trying: the setting lives
     * behind the superadmin-only platform-settings endpoint, so an operator
     * met the cap as a 422 on a question they had already written. It depends
     * on the project's `assessment_type`, which is exactly what this route
     * already resolved.
     *
     * @scramble-return array{data: array<int, array{id: int, project_id: int, competency_id: int, competency_code: string|null, text: array<string, string>, position: int, created_at: string, updated_at: string}>, meta: array{max_questions_per_competency: int}}
     */
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
        )->additional([
            'meta' => [
                'max_questions_per_competency' => app(PlatformSettings::class)
                    ->maxQuestionsPerCompetency((string) $project->assessment_type),
            ],
        ]);
    }

    public function store(StoreProjectQuestionRequest $request, int $project): JsonResponse
    {
        $project = $this->resolveProject($project);
        $validated = $request->validated();

        // Appended at the end of ITS competency's list. Derived rather than
        // accepted from the client because the answer a caller wants is always
        // "last", and a supplied position is one more thing to validate
        // against a state only the server can see.
        //
        // This is NOT a concurrency guarantee, and an earlier comment here
        // claimed it was. Two simultaneous adds to the same competency both
        // read the same max and both compute max+1; the partial unique index
        // is what refuses the second, not this derivation. That is acceptable
        // rather than defended: the cap is single digits per competency, the
        // (N+1)th is already refused upstream, and two operators writing the
        // same competency in the same instant is not a workflow this product
        // has. What is not acceptable is a comment promising otherwise.
        //
        // `max()` returns null on an empty competency, which is exactly the
        // question the separate `exists()` call used to ask.
        $max = ProjectQuestion::where('project_id', $project->id)
            ->where('competency_id', $validated['competency_id'])
            ->max('position');

        $question = ProjectQuestion::create([
            'project_id' => $project->id,
            'competency_id' => $validated['competency_id'],
            'text' => $validated['text'],
            'position' => $max === null ? 0 : (int) $max + 1,
        ]);

        return ProjectQuestionResource::make($question->load('competency'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateProjectQuestionRequest $request, int $project, int $questionId): ProjectQuestionResource
    {
        $project = $this->resolveProject($project);
        $this->authorize('update', $project);

        $question = ProjectQuestion::where('project_id', $project->id)->findOrFail($questionId);

        // Only the wording — see UpdateProjectQuestionRequest for why the
        // competency is not movable. The rules used to live inline here, in
        // parallel with StoreProjectQuestionRequest's: raising `max:2000` in
        // one would have left the other silently disagreeing.
        $question->update(['text' => $request->validated('text')]);

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

        // Codes, like every other rule in this file. `$request->validate()`
        // answers "The ids field is required." in English, and the backoffice
        // can only print what it has no key for.
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ], [
            'ids.required' => 'ids_required',
            'ids.array' => 'ids_invalid',
            'ids.min' => 'ids_required',
            'ids.*.required' => 'ids_invalid',
            'ids.*.integer' => 'ids_invalid',
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

        // And every id must be there. The check above proves only BELONGING,
        // which any subset of valid ids satisfies — and a subset renumbers
        // from 0 while the rows it omitted still hold those positions, so the
        // partial unique index rejects the write and an operator's reorder
        // came back as a 500. The list is drag-and-drop's whole ordered group,
        // so the rule is per competency: whatever competencies this payload
        // touches, it must carry ALL of their live questions.
        $touched = $questions->pluck('competency_id')->unique()->all();

        $live = ProjectQuestion::where('project_id', $project->id)
            ->whereIn('competency_id', $touched)
            ->pluck('id')
            ->all();

        if (count($live) !== count($ids) || array_diff($live, $ids) !== []) {
            return response()->json(
                [
                    'message' => 'Send every question of the competencies being reordered.',
                    'code' => 'QUESTION_SET_INCOMPLETE',
                ],
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
