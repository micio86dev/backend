<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `catalog_meta` singleton table (C3 Framework Catalog).
 *
 * Single-row cache-busting counter. Bumped by the seeder on structural changes.
 * Used for "catalog changed" signalling — NOT a per-version discriminator.
 * CatalogMeta::bump() ensures id=1 row always exists and increments revision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_meta');
    }
};
