<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deal line items (decision D-8).
 *
 * A line copies the product's unit price at the time it is added so a later
 * catalogue change never rewrites a deal. `line_total` is computed by
 * DealProductObserver (quantity × unit price less the discount, CHECK-constrained
 * to 0–100 %) and the deal's amount is the sum of its lines whenever it has any.
 * Lines follow their deal when it is permanently removed; a product on a line
 * is protected (RESTRICT).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['deal_id', 'sort'], 'deal_products_deal_id_sort_index');
        });

        DB::statement('ALTER TABLE `deal_products` ADD CONSTRAINT `deal_products_discount_percent_check` CHECK (`discount_percent` BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        // The discount CHECK is dropped together with the table.
        Schema::dropIfExists('deal_products');
    }
};
