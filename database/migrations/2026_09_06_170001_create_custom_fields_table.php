<?php

declare(strict_types=1);

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom field definitions (decision D-9, plan module row 15, design row
 * `custom_fields`).
 *
 * One row is one administrator-defined field on a lead, contact, account or
 * deal. `key` is the stable machine name the form, the table, the filters and
 * the import/export columns address the field by — it never changes after
 * creation, so a saved view or a CSV header keeps working. `type` decides
 * which typed column of `custom_field_values` the value is written to
 * (CustomFieldType::valueColumn(), never a JSON bag); `options` holds the
 * bilingual choices of a select, `validation` the type's own constraints
 * (min/max/step, min_length/max_length/regex).
 *
 * Definitions are settings, not business entities: no soft deletes — a field
 * that already carries values is deactivated rather than removed
 * (CustomFieldService::isDeletable()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table): void {
            $table->id();
            $table->string('entity', 32);
            $table->string('key', 50);
            $table->string('label_ar', 100);
            $table->string('label_en', 100);
            $table->string('type', 32);
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_listed')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->json('validation')->nullable();
            $table->timestamps();

            $table->unique(['entity', 'key'], 'custom_fields_entity_key_unique');
            $table->index('entity', 'custom_fields_entity_index');
            $table->index(['entity', 'is_active', 'sort'], 'custom_fields_entity_is_active_sort_index');
        });

        EnumCheck::apply('custom_fields', 'entity', CustomFieldEntity::class);
        EnumCheck::apply('custom_fields', 'type', CustomFieldType::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};
