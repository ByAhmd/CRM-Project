<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tag assignments (decision A-4).
 *
 * Keyless polymorphic pivot between `tags` and any taggable entity (lead,
 * contact, account, deal). The composite primary key makes a tag attachable
 * to a record only once; the (`taggable_type`, `taggable_id`) index serves
 * the per-record lookup. Rows follow their tag: deleting a tag detaches it
 * everywhere (CASCADE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taggables', function (Blueprint $table): void {
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->string('taggable_type', 100);
            $table->unsignedBigInteger('taggable_id');

            $table->primary(['tag_id', 'taggable_type', 'taggable_id'], 'taggables_primary');
            $table->index(['taggable_type', 'taggable_id'], 'taggables_taggable_type_taggable_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
    }
};
