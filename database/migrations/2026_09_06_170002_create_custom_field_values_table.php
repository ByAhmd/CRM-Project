<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom field values (decision D-9, design row `custom_field_values`).
 *
 * One row is one field's value on one record. Storage is typed, never a JSON
 * bag: the definition's type names the single column the value lives in
 * (CustomFieldType::valueColumn()), every other column stays null, and
 * `value_json` is reserved for multiselect — a list of option values. That is
 * what lets a value be filtered, sorted and indexed like a real column.
 *
 * The row belongs to its definition (CASCADE: dropping a definition drops its
 * values) and points at the record polymorphically. The unique key is the
 * invariant that one field holds at most one value per record; the composite
 * indexes on the string, integer and date columns back the table filters and
 * the correlated-subquery sorts of CustomFieldsSchema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custom_field_id')->constrained('custom_fields')->cascadeOnDelete();
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id');
            $table->string('value_string', 500)->nullable();
            $table->text('value_text')->nullable();
            $table->bigInteger('value_integer')->nullable();
            $table->decimal('value_decimal', 18, 4)->nullable();
            $table->date('value_date')->nullable();
            $table->dateTime('value_datetime')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->unique(['custom_field_id', 'entity_type', 'entity_id'], 'custom_field_values_field_entity_unique');
            $table->index(['entity_type', 'entity_id'], 'custom_field_values_entity_index');
            $table->index(['custom_field_id', 'value_string'], 'custom_field_values_field_string_index');
            $table->index(['custom_field_id', 'value_integer'], 'custom_field_values_field_integer_index');
            $table->index(['custom_field_id', 'value_date'], 'custom_field_values_field_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
    }
};
