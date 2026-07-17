<?php

declare(strict_types=1);

/**
 * Test-only isolation routes (C2 — Phase 3).
 *
 * These routes are loaded EXCLUSIVELY in APP_ENV=testing and provide a
 * minimal CRUD surface for SampleTenantRecord, used by the cross-tenant
 * isolation matrix tests.
 *
 * In production these routes are never registered — the file is loaded
 * conditionally in AppServiceProvider::boot() when APP_ENV=testing.
 *
 * Route prefix: /api/test-isolation/sample-tenant-records
 * Middleware:   auth:api (so TenantContext fires via api group)
 */

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Models\SampleTenantRecord;

Route::middleware('auth:api')->prefix('test-isolation')->group(function (): void {
    // CREATE — returns 201 with the created record (including the stamped organization_id).
    Route::post('/sample-tenant-records', function (Request $request): JsonResponse {
        $record = SampleTenantRecord::create([
            'title' => $request->input('title', 'test record'),
            'organization_id' => $request->input('organization_id'),  // overridden by creating listener
        ]);

        return response()->json($record->toArray(), 201);
    });

    // UPDATE — returns 200 or 404.
    Route::put('/sample-tenant-records/{id}', function (Request $request, int $id): JsonResponse {
        $record = SampleTenantRecord::find($id);

        if ($record === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $record->update(['title' => $request->input('title', $record->title)]);

        return response()->json($record->toArray());
    });

    // DELETE — returns 200 or 404.
    Route::delete('/sample-tenant-records/{id}', function (int $id): JsonResponse {
        $record = SampleTenantRecord::find($id);

        if ($record === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $record->delete();

        return response()->json(['message' => 'Deleted.']);
    });
});
