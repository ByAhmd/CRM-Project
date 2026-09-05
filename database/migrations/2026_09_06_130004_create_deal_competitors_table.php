<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Competitors named on a deal (decision D-8).
 *
 * Each competitor appears once per deal; `is_winner` marks who a lost deal
 * went to. Rows follow their deal when it is permanently removed, while a
 * referenced competitor is protected (RESTRICT) so a closed deal keeps naming
 * who it was lost to (D-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_competitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('competitor_id')->constrained('competitors')->restrictOnDelete();
            $table->boolean('is_winner')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['deal_id', 'competitor_id'], 'deal_competitors_deal_id_competitor_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_competitors');
    }
};
