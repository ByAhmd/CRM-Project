<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Industries (decision A-4).
 *
 * A bilingual lookup classifying accounts and leads by the sector they
 * operate in. Referencing tables point at it with RESTRICT so a sector in
 * use cannot vanish; the lookup itself is not soft-deleted because it carries
 * no history of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industries', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique('name_ar', 'industries_name_ar_unique');
            $table->unique('name_en', 'industries_name_en_unique');
            $table->index(['is_active', 'sort'], 'industries_is_active_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('industries');
    }
};
