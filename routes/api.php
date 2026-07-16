<?php

declare(strict_types=1);

// TODO(D33): Versioning contract — additive changes are non-breaking;
// breaking changes require a new /api/v2/ prefix, coordinated across consumers.
// See docs/api-versioning.md for the full contract.

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
