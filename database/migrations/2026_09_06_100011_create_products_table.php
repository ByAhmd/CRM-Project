<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product catalogue (decision D-8).
 *
 * Deal line items (`deal_products`) reference a product and copy its unit
 * price at the time the line is added, so a later price change never rewrites
 * a closed deal. Prices are in the organisation's single currency (D-8).
 * Products are soft-deleted so historical lines stay explainable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->nullable();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('code', 'products_code_unique');
            $table->unique('name_ar', 'products_name_ar_unique');
            $table->unique('name_en', 'products_name_en_unique');
            $table->index('is_active', 'products_is_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
