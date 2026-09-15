<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Models\FrameworkCatalogRevision;
use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The blocking publish sweep (framework-catalogue-authoring PR3, D3).
 *
 * `violations()` is a PURE read — every check named at once, never the
 * first failure only, so a superadmin fixing one problem at a time is not
 * forced through the sweep repeatedly to discover the next. `publish()`
 * wraps it in the transaction that actually flips `state`, `SELECT … FOR
 * UPDATE` on the revision row so two concurrent publish attempts on the
 * same draft cannot both observe zero violations and both write.
 *
 * LITERAL 83/85 counts are NOT checked here — see
 * `CatalogueBaselineLiteralCountsTest` (10.2/11.4): a literal count enforced
 * on every publish would refuse the first competency a superadmin ever
 * adds. Only STRUCTURAL rules are enforced on every publish (design D3,
 * Contradiction 2): exactly 3 indicators per DECLARED pair, anchors present,
 * no `potential` competency in the pivot, no role-scoped MTG/LAT indicator,
 * and no cross-role duplicate anchor text NEW to this revision.
 */
final class PublishRevision
{
    /**
     * @return list<array{rule: string, subject: string, detail: string}>
     */
    public function violations(FrameworkCatalogRevision $revision): array
    {
        $violations = [];

        $violations = [...$violations, ...$this->indicatorCountViolations($revision->id)];
        $violations = [...$violations, ...$this->emptyPairViolations($revision->id)];
        $violations = [...$violations, ...$this->potentialInPivotViolations($revision->id)];
        $violations = [...$violations, ...$this->roleScopedPotentialIndicatorViolations($revision->id)];
        $violations = [...$violations, ...$this->potentialIndicatorCountViolations($revision->id)];
        $violations = [...$violations, ...$this->crossRoleDuplicateDeltaViolations($revision)];

        return $violations;
    }

    /**
     * @return list<array{rule: string, subject: string, detail: string}>
     */
    public function publish(FrameworkCatalogRevision $revision, User $actor): array
    {
        return DB::transaction(function () use ($revision, $actor): array {
            // Lock the row for the duration of the sweep + flip — two
            // concurrent publish attempts on the same draft must not both
            // observe zero violations and both write.
            $locked = FrameworkCatalogRevision::whereKey($revision->getKey())->lockForUpdate()->firstOrFail();

            $violations = $this->violations($locked);

            if ($violations !== []) {
                return $violations;
            }

            $locked->state = 'published';
            $locked->published_at = now()->toImmutable();
            $locked->published_by_user_id = $actor->id;
            $locked->save();

            return [];
        });
    }

    /**
     * Exactly 3 indicators per DECLARED pair (a row in the pivot). Mirrors
     * `scripts/ci-guards.sh:2352`.
     *
     * @return list<array{rule: string, subject: string, detail: string}>
     */
    private function indicatorCountViolations(int $revisionId): array
    {
        $rows = DB::table('framework_bars_indicators')
            ->select('role_id', 'competency_id', DB::raw('count(*) as total'))
            ->where('revision_id', $revisionId)
            ->whereNotNull('role_id')
            ->groupBy('role_id', 'competency_id')
            ->having(DB::raw('count(*)'), '!=', 3)
            ->get();

        return array_values($rows->map(fn (object $row): array => [
            'rule' => 'exactly_three_indicators',
            'subject' => "role:{$row->role_id} competency:{$row->competency_id}",
            'detail' => "expected exactly 3 indicators, found {$row->total}",
        ])->all());
    }

    /**
     * A pivot row (a DECLARED pair) with zero indicators refuses publish —
     * an empty stub, never anchored at all.
     *
     * @return list<array{rule: string, subject: string, detail: string}>
     */
    private function emptyPairViolations(int $revisionId): array
    {
        $rows = DB::table('framework_role_competency as prc')
            ->leftJoin('framework_bars_indicators as bi', function (JoinClause $join) use ($revisionId): void {
                $join->on('bi.role_id', '=', 'prc.role_id')
                    ->on('bi.competency_id', '=', 'prc.competency_id')
                    ->where('bi.revision_id', $revisionId);
            })
            ->where('prc.revision_id', $revisionId)
            ->select('prc.role_id', 'prc.competency_id', DB::raw('count(bi.id) as total'))
            ->groupBy('prc.role_id', 'prc.competency_id')
            ->having(DB::raw('count(bi.id)'), '=', 0)
            ->get();

        return array_values($rows->map(fn (object $row): array => [
            'rule' => 'pair_must_be_anchored',
            'subject' => "role:{$row->role_id} competency:{$row->competency_id}",
            'detail' => 'declared pair has zero indicators',
        ])->all());
    }

    /**
     * A `potential` competency MUST NOT appear in the role pivot — it
     * belongs to no role by rule (MTG/LAT).
     *
     * @return list<array{rule: string, subject: string, detail: string}>
     */
    private function potentialInPivotViolations(int $revisionId): array
    {
        $rows = DB::table('framework_role_competency as prc')
            ->join('framework_competencies as c', 'c.id', '=', 'prc.competency_id')
            ->where('prc.revision_id', $revisionId)
            ->where('c.type', 'potential')
            ->select('prc.role_id', 'prc.competency_id', 'c.code')
            ->get();

        return array_values($rows->map(fn (object $row): array => [
            'rule' => 'potential_competency_not_in_pivot',
            'subject' => "role:{$row->role_id} competency:{$row->competency_id}",
            'detail' => "potential competency {$row->code} must not be assigned to a role",
        ])->all());
    }

    /**
     * A `potential` competency's indicators MUST carry `role_id = null` —
     * mirrors `CI_NON_ROLE_BARS_FILES` (`scripts/ci-guards.sh:549`).
     *
     * @return list<array{rule: string, subject: string, detail: string}>
     */
    private function roleScopedPotentialIndicatorViolations(int $revisionId): array
    {
        $rows = DB::table('framework_bars_indicators as bi')
            ->join('framework_competencies as c', 'c.id', '=', 'bi.competency_id')
            ->where('bi.revision_id', $revisionId)
            ->where('c.type', 'potential')
            ->whereNotNull('bi.role_id')
            ->select('bi.id', 'bi.role_id', 'c.code')
            ->get();

        return array_values($rows->map(fn (object $row): array => [
            'rule' => 'potential_indicator_must_be_role_less',
            'subject' => "indicator:{$row->id}",
            'detail' => "indicator for potential competency {$row->code} must have role_id = null, found role:{$row->role_id}",
        ])->all());
    }

    /**
     * Exactly 3 role-less indicators per `potential` competency (gga review
     * finding, blocking): `indicatorCountViolations()` filters
     * `whereNotNull('role_id')` and `emptyPairViolations()` only walks the
     * PIVOT — neither ever looks at MTG/LAT, which have no pivot row and a
     * null `role_id` by rule. Without this check, deleting one of a
     * `potential` competency's three indicators in the draft and publishing
     * shipped a revision with an incomplete triad and no violation raised.
     * A LEFT JOIN from `framework_competencies` (not a plain GROUP BY over
     * `framework_bars_indicators`) so a `potential` competency with ZERO
     * indicators is also caught, the same way `emptyPairViolations()`
     * catches a zero-indicator declared pair.
     *
     * @return list<array{rule: string, subject: string, detail: string}>
     */
    private function potentialIndicatorCountViolations(int $revisionId): array
    {
        $rows = DB::table('framework_competencies as c')
            ->leftJoin('framework_bars_indicators as bi', function (JoinClause $join) use ($revisionId): void {
                $join->on('bi.competency_id', '=', 'c.id')
                    ->whereNull('bi.role_id')
                    ->where('bi.revision_id', $revisionId);
            })
            ->where('c.revision_id', $revisionId)
            ->where('c.type', 'potential')
            ->select('c.id as competency_id', 'c.code', DB::raw('count(bi.id) as total'))
            ->groupBy('c.id', 'c.code')
            ->having(DB::raw('count(bi.id)'), '!=', 3)
            ->get();

        return array_values($rows->map(fn (object $row): array => [
            'rule' => 'exactly_three_indicators',
            'subject' => "competency:{$row->competency_id}",
            'detail' => "potential competency {$row->code} expected exactly 3 role-less indicators, found {$row->total}",
        ])->all());
    }

    /**
     * The DELTA cross-role duplicate check (design D3, Contradiction 3):
     * blocking only for a duplicate NEW to this revision. `MLL.json:142`/
     * `BUL.json:142` and `FLL.json:176`/`MLL.json:176` already carry
     * identical strings in the shipped baseline — a blocking rule with no
     * delta would refuse publishing a clone of the catalogue this platform
     * already ships.
     *
     * Compares, per competency, every pair of (different-role) indicators
     * sharing byte-identical text in the SAME field (`text`/`anchor_5`/
     * `anchor_3`/`anchor_1`) and the SAME locale. A duplicate pair is
     * refused only when it is NOT already present, identically, in the
     * revision's PARENT (`parent_revision_id` — see that column's own
     * migration). A revision with no parent (the baseline itself, or any
     * revision that predates this column) has nothing to diff against, so
     * every duplicate it carries is — by definition — not new; the check is
     * a no-op for it, matching the baseline's own already-known,
     * already-excused duplicates.
     *
     * @return list<array{rule: string, subject: string, detail: string}>
     */
    private function crossRoleDuplicateDeltaViolations(FrameworkCatalogRevision $revision): array
    {
        if ($revision->parent_revision_id === null) {
            return [];
        }

        $current = $this->duplicatePairs($revision->id);
        $parent = $this->duplicatePairs($revision->parent_revision_id);

        $parentKeys = array_flip(array_map(
            fn (array $pair): string => $pair['key'],
            $parent,
        ));

        $violations = [];

        foreach ($current as $pair) {
            if (isset($parentKeys[$pair['key']])) {
                continue;
            }

            $violations[] = [
                'rule' => 'cross_role_duplicate_anchor_new_to_revision',
                'subject' => "competency:{$pair['competency_code']} field:{$pair['field']} locale:{$pair['locale']}",
                'detail' => "role:{$pair['role_a']} and role:{$pair['role_b']} share identical text, new to this revision",
            ];
        }

        return $violations;
    }

    /**
     * Every cross-role duplicate-text pair for a revision, keyed by
     * competency + field + locale + the two role ids involved (order-
     * independent) — comparable across revisions regardless of the actual
     * row/role ids each revision's clone was assigned, because roles keep
     * their CODE across a clone even though their numeric id changes.
     *
     * @return list<array{key: string, competency_code: string, field: string, locale: string, role_a: string, role_b: string}>
     */
    private function duplicatePairs(int $revisionId): array
    {
        // Joined to COMPETENCY CODE too, not just role code: numeric ids
        // are per-revision (a clone gets brand-new rows, D1), so comparing
        // raw competency_id across a parent/child pair would never match
        // even for "the same" competency. Code is what survives a clone.
        $indicators = DB::table('framework_bars_indicators as bi')
            ->join('framework_roles as r', 'r.id', '=', 'bi.role_id')
            ->join('framework_competencies as c', 'c.id', '=', 'bi.competency_id')
            ->where('bi.revision_id', $revisionId)
            ->whereNotNull('bi.role_id')
            ->select('c.code as competency_code', 'r.code as role_code', 'bi.text', 'bi.anchor_5', 'bi.anchor_3', 'bi.anchor_1')
            ->get();

        /** @var array<string, list<string>> $byBucket bucket key => list of role codes carrying that exact text */
        $byBucket = [];

        foreach ($indicators as $indicator) {
            foreach (['text', 'anchor_5', 'anchor_3', 'anchor_1'] as $field) {
                $localeMap = json_decode((string) $indicator->{$field}, true) ?? [];

                foreach ($localeMap as $locale => $value) {
                    if (! is_string($value) || trim($value) === '') {
                        continue;
                    }

                    $bucketKey = "{$indicator->competency_code}|{$field}|{$locale}|{$value}";
                    $byBucket[$bucketKey][] = $indicator->role_code;
                }
            }
        }

        $pairs = [];

        foreach ($byBucket as $bucketKey => $roleCodes) {
            $roleCodes = array_unique($roleCodes);
            sort($roleCodes);

            if (count($roleCodes) < 2) {
                continue;
            }

            // The comparison key deliberately includes the FULL bucket key
            // (competency|field|locale|VALUE), not merely competency/field/
            // locale/roles: two different indicator strings that happen to
            // duplicate across the SAME two roles for the SAME field are
            // two SEPARATE duplicates, not one — one may be the already-
            // excused parent duplicate while the other is genuinely new.
            [$competencyCode, $field, $locale] = explode('|', $bucketKey, 4);

            for ($i = 0; $i < count($roleCodes); $i++) {
                for ($j = $i + 1; $j < count($roleCodes); $j++) {
                    $pairs[] = [
                        'key' => "{$bucketKey}|{$roleCodes[$i]}|{$roleCodes[$j]}",
                        'competency_code' => $competencyCode,
                        'field' => $field,
                        'locale' => $locale,
                        'role_a' => $roleCodes[$i],
                        'role_b' => $roleCodes[$j],
                    ];
                }
            }
        }

        return $pairs;
    }
}
