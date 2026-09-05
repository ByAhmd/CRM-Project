<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Competitors named on deals (decisions A-4, D-8).
 *
 * A competitor is a configurable lookup maintained under Settings and linked
 * to deals through `deal_competitors`. Names are proper nouns, so the lookup
 * carries a single `name` rather than the bilingual pair. Competitors are
 * soft-deleted so a closed deal keeps naming who it was lost to (D-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitors', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('website', 255)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('name', 'competitors_name_unique');
            $table->index('is_active', 'competitors_is_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitors');
    }
};
