<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A predefined question an interview opens a competency with
 * (potential-competencies-and-authored-questions).
 *
 * Extends TenantModel, so `organization_id` is stamped by TenantScoped and
 * every read is scoped without the caller remembering to.
 *
 * SOFT DELETED deliberately, not by habit: a deleted question is still
 * referenced by interviews already conducted under it, and a hard delete would
 * leave an existing transcript with no explanation of what was asked.
 *
 * How MANY questions a competency may have is NOT enforced here. `standard`
 * allows one and `potential` requires four, which is a function of the
 * project's assessment type and belongs beside the other type invariants in
 * the FormRequests — putting it in the model would mean re-deriving the type
 * on every write and would still not cover a direct DB seed.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $project_id
 * @property int $competency_id
 * @property array<string, string> $text Locale map, `{"en": …, "it": …}` — the
 *                                       same shape the catalogue uses, because i18n is mandatory it/en and
 *                                       the question must reach the candidate in the project's language.
 * @property int $position
 * @property bool $operator_modified Provenance flag (framework-catalogue-authoring
 *                                   PR5, D9) — TRUE once an operator has authored or
 *                                   edited this row's text; FALSE for a row still
 *                                   carrying its catalogue-default copy verbatim. Set
 *                                   TRUE by `ProjectQuestionController::store()`/`update()`
 *                                   unconditionally, via direct attribute assignment.
 *                                   `ApplyCompetencySelection`'s auto-fill does NOT
 *                                   write it at all — the column's own `DEFAULT false`
 *                                   supplies the value, since that write path must
 *                                   never claim a copied row as operator-authored. A
 *                                   restore never writes it either — the restored row
 *                                   keeps whatever it already had. Never compared against
 *                                   the current catalogue
 *                                   default text: a flag records what happened, a
 *                                   comparison would guess, and guess differently as
 *                                   the default changes later.
 * @property bool $deleted_by_deselection Z8 (framework-catalogue-authoring,
 *                                        REQUIRED BEFORE ARCHIVE) — TRUE when this row's
 *                                        soft-delete was `ApplyCompetencySelection::softDeleteLive()`
 *                                        deselecting the competency; FALSE for a row an operator
 *                                        deleted individually. `restore()` reads this to resurrect
 *                                        only rows a deselection removed — an individually deleted
 *                                        question stays deleted on reselection. UNLIKE `operator_modified`,
 *                                        a restore DOES reset this back to false: once the row is live
 *                                        again, it is no longer "trashed by a deselection", and leaving
 *                                        it true would let a later individual delete of the SAME row be
 *                                        misread as deselection-caused on the next cycle.
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Project|null $project
 * @property-read Competency|null $competency
 */
class ProjectQuestion extends TenantModel
{
    use SoftDeletes;

    /**
     * `organization_id` is absent on purpose — TenantScoped stamps it
     * unconditionally on create, and accepting it here would let a request
     * payload propose a different tenant.
     *
     * `operator_modified` is absent on purpose too: it is never accepted
     * from a request payload, only set by the controller/action that already
     * knows which write path produced the row (see the property docblock).
     * `deleted_by_deselection` (Z8) is the same shape — only
     * `ApplyCompetencySelection::softDeleteLive()` ever writes it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'competency_id',
        'text',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'text' => 'array',
            'position' => 'integer',
            'operator_modified' => 'boolean',
            'deleted_by_deselection' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Competency, $this>
     */
    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }
}
