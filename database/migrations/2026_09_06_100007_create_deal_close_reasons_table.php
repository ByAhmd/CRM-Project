<?php

declare(strict_types=1);

use App\Enums\CloseReasonKind;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable reasons a deal was won or lost (decisions D-8, A-4).
 *
 * Each reason belongs to one kind (won / lost, CHECK-constrained) and a deal
 * references one through deals.close_reason_id once it is closed. Names are
 * unique per kind, so "Price" may explain both a win and a loss. Reasons are
 * never soft-deleted: a referenced reason is protected by the FK on deals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_close_reasons', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32);
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['kind', 'name_ar'], 'deal_close_reasons_kind_name_ar_unique');
            $table->unique(['kind', 'name_en'], 'deal_close_reasons_kind_name_en_unique');
            $table->index('kind', 'deal_close_reasons_kind_index');
            $table->index(['is_active', 'sort'], 'deal_close_reasons_is_active_sort_index');
        });

        EnumCheck::apply('deal_close_reasons', 'kind', CloseReasonKind::class);
    }

    public function down(): void
    {
        if (Schema::hasTable('deal_close_reasons')) {
            EnumCheck::drop('deal_close_reasons', 'kind');
        }

        Schema::dropIfExists('deal_close_reasons');
    }
};
