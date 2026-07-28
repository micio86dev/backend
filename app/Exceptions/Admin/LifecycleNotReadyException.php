<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown by LifecycleReadGate::assert() (C11) when a participant's lifecycle
 * status has not yet reached the minimum threshold required by the requested
 * ParticipantReadScope (D2).
 *
 * D4: renders HTTP 409 Conflict, not 403 — the caller is an authorized admin
 * of the owning organization and the resource exists; the denial is temporal
 * and self-resolving (the same request succeeds once scoring completes, with
 * no change to identity, role, or grant). Registered in bootstrap/app.php
 * beside the existing exception renders (:51-64).
 *
 * `current_status` is safe to disclose: a cross-org request already 404s
 * before this exception can be thrown (AdminParticipantReader's org filter,
 * D1, runs before the lifecycle gate), and the participant list already
 * exposes the same field to the same caller.
 *
 * REQ: Lifecycle Read-Gate (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */
class LifecycleNotReadyException extends Exception
{
    public function __construct(
        public readonly string $resource,
        public readonly string $currentStatus,
        public readonly string $requiredStatus,
    ) {
        parent::__construct(
            "Resource '{$resource}' is not ready: current status '{$currentStatus}', requires '{$requiredStatus}'."
        );
    }

    /**
     * Render as HTTP 409 with a machine-readable, non-localized body (D4).
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'lifecycle_not_ready',
            'resource' => $this->resource,
            'current_status' => $this->currentStatus,
            'required_status' => $this->requiredStatus,
        ], Response::HTTP_CONFLICT);
    }
}
