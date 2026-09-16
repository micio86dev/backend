<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Models\FrameworkCatalogRevision;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Open (or continue) the one draft revision a superadmin edits (framework-
 * catalogue-authoring PR3, D1).
 *
 * `framework_catalog_revisions_one_draft` permits at most one open draft on
 * the whole platform, so this action is idempotent by construction: if a
 * draft already exists, it is returned unchanged (`continue`); otherwise the
 * LATEST published revision is cloned — every role, competency, BARS
 * indicator, pivot row, and default question — into a brand-new `draft` row,
 * inside one transaction.
 *
 * ID REMAPPING, not a literal `INSERT ... SELECT` per table. Design.md's
 * pseudocode describes "one INSERT … SELECT per table", but that cannot be
 * correct as written: `framework_role_competency`/`framework_bars_indicators`/
 * `framework_default_questions` all carry composite foreign keys tying
 * `(role_id|competency_id, revision_id)` back to the PARENT's roles/
 * competencies rows. A cloned pivot row pointing at the OLD role_id but the
 * NEW revision_id would violate that exact composite FK — the one thing this
 * schema exists to make impossible (D1). Every child row's `role_id`/
 * `competency_id` is therefore rewritten to the newly-inserted row's id, via
 * an in-memory old-id -> new-id map built while roles and competencies are
 * cloned first. Still one transaction, still ~450 rows, still zero hand
 * -editing of anchor text — the correctness property design.md actually
 * asks for.
 */
final class OpenDraftRevision
{
    public function open(): FrameworkCatalogRevision
    {
        $existingDraft = FrameworkCatalogRevision::openDraft();

        if ($existingDraft !== null) {
            return $existingDraft;
        }

        try {
            return DB::transaction(function (): FrameworkCatalogRevision {
                // K7 (framework-catalogue-authoring PR4b): resolved through
                // the ONE `latestPublished()` implementation every other
                // "latest published revision" reader now shares — see that
                // method's own docblock.
                $parent = FrameworkCatalogRevision::latestPublished()
                    ?? throw new ModelNotFoundException('framework_catalog_revisions: no published revision exists to clone a draft from.');

                $draft = FrameworkCatalogRevision::create(['state' => 'draft', 'parent_revision_id' => $parent->id]);

                $roleIdMap = $this->cloneRoles($parent->id, $draft->id);
                $competencyIdMap = $this->cloneCompetencies($parent->id, $draft->id);
                $this->clonePivot($parent->id, $draft->id, $roleIdMap, $competencyIdMap);
                $this->cloneBarsIndicators($parent->id, $draft->id, $roleIdMap, $competencyIdMap);
                $this->cloneDefaultQuestions($parent->id, $draft->id, $competencyIdMap);

                return $draft;
            });
        } catch (QueryException $e) {
            if (! $this->isOneDraftUniqueViolation($e)) {
                throw $e;
            }

            // H4 (framework-catalogue-authoring PR3b): a concurrent caller
            // won the race for `framework_catalog_revisions_one_draft`
            // between our own `openDraft()` call above and this
            // transaction's `INSERT` — the whole transaction (including
            // whatever this attempt had already cloned) rolled back
            // automatically. The LOSER continues the WINNER's draft rather
            // than surfacing the constraint violation as a 500: `open()`'s
            // whole contract is "return THE open draft, however it got
            // there", and the winner's draft is exactly that.
            return FrameworkCatalogRevision::openDraft()
                ?? throw new ModelNotFoundException('framework_catalog_revisions: expected the winning draft to exist after losing the one-draft race.');
        }
    }

    /**
     * Postgres names the exact constraint it refused (SQLSTATE 23505); the
     * driver message contains it verbatim. Scoped to this ONE index
     * deliberately — any other unique violation inside the transaction (a
     * genuine data problem, not a benign race) must still surface.
     */
    private function isOneDraftUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505'
            && str_contains($e->getMessage(), 'framework_catalog_revisions_one_draft');
    }

    /**
     * @return array<int, int> old role id => new role id
     */
    private function cloneRoles(int $parentRevisionId, int $draftRevisionId): array
    {
        $map = [];

        foreach (DB::table('framework_roles')->where('revision_id', $parentRevisionId)->get() as $role) {
            $map[$role->id] = DB::table('framework_roles')->insertGetId([
                'revision_id' => $draftRevisionId,
                'code' => $role->code,
                'name' => $role->name,
                'responsibilities' => $role->responsibilities,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $map;
    }

    /**
     * @return array<int, int> old competency id => new competency id
     */
    private function cloneCompetencies(int $parentRevisionId, int $draftRevisionId): array
    {
        $map = [];

        foreach (DB::table('framework_competencies')->where('revision_id', $parentRevisionId)->get() as $competency) {
            $map[$competency->id] = DB::table('framework_competencies')->insertGetId([
                'revision_id' => $draftRevisionId,
                'code' => $competency->code,
                'type' => $competency->type,
                'name' => $competency->name,
                'definition' => $competency->definition,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $roleIdMap
     * @param  array<int, int>  $competencyIdMap
     */
    private function clonePivot(int $parentRevisionId, int $draftRevisionId, array $roleIdMap, array $competencyIdMap): void
    {
        $rows = [];

        foreach (DB::table('framework_role_competency')->where('revision_id', $parentRevisionId)->get() as $pivot) {
            $rows[] = [
                'revision_id' => $draftRevisionId,
                'role_id' => $roleIdMap[$pivot->role_id],
                'competency_id' => $competencyIdMap[$pivot->competency_id],
                'position' => $pivot->position,
            ];
        }

        if ($rows !== []) {
            DB::table('framework_role_competency')->insert($rows);
        }
    }

    /**
     * @param  array<int, int>  $roleIdMap
     * @param  array<int, int>  $competencyIdMap
     */
    private function cloneBarsIndicators(int $parentRevisionId, int $draftRevisionId, array $roleIdMap, array $competencyIdMap): void
    {
        $rows = [];

        foreach (DB::table('framework_bars_indicators')->where('revision_id', $parentRevisionId)->get() as $indicator) {
            $rows[] = [
                'revision_id' => $draftRevisionId,
                // Role-less rows (MTG/LAT, `potential`) stay role-less.
                'role_id' => $indicator->role_id === null ? null : $roleIdMap[$indicator->role_id],
                'competency_id' => $competencyIdMap[$indicator->competency_id],
                'text' => $indicator->text,
                'anchor_5' => $indicator->anchor_5,
                'anchor_3' => $indicator->anchor_3,
                'anchor_1' => $indicator->anchor_1,
                'position' => $indicator->position,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('framework_bars_indicators')->insert($rows);
        }
    }

    /**
     * @param  array<int, int>  $competencyIdMap
     */
    private function cloneDefaultQuestions(int $parentRevisionId, int $draftRevisionId, array $competencyIdMap): void
    {
        $rows = [];

        foreach (DB::table('framework_default_questions')->where('revision_id', $parentRevisionId)->get() as $question) {
            $rows[] = [
                'revision_id' => $draftRevisionId,
                'competency_id' => $competencyIdMap[$question->competency_id],
                'text' => $question->text,
                'position' => $question->position,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('framework_default_questions')->insert($rows);
        }
    }
}
