<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Audit\AuditBatchResult;
use App\DTOs\Audit\AuditRequest;
use App\Exceptions\Audit\AuditJudgeException;

/**
 * Post-hoc audit judgment contract (scoring-audit-jev, proposal AD-5, design
 * D2). Judges whether each subject's PERSISTED excerpts and explanation
 * support its PERSISTED score. Deliberately NOT `LLMProvider`: that contract
 * is a one-shot text completion (`complete(): LLMResponse`), while the judge
 * returns a typed judgment over several parallel questions and generates no
 * text — see AD-5's "the abstraction would be lossy in the direction that
 * loses the actual answer".
 */
interface AuditJudge
{
    /**
     * Judge whether each subject's persisted excerpts and explanation
     * support its persisted score.
     *
     * @throws AuditJudgeException on transport failure, non-2xx, or an
     *                             unparseable envelope. NEVER on a per-subject
     *                             problem — a missing or malformed verdict is
     *                             reported by its ABSENCE from the result map
     *                             (`AuditBatchResult::$verdicts`), never by
     *                             throwing for that one subject.
     */
    public function judge(AuditRequest $request): AuditBatchResult;
}
