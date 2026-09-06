<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved table views (decision A-8, plan module row 17, design row `saved_views`).
 *
 * One row is one user's remembered table state for one resource: the filter
 * form state, the sort, the search and the toggled columns. `resource` is the
 * Filament resource slug (`leads`, `deals`, …) so the row survives a class
 * rename. A view is private unless `is_shared`; `is_default` marks the one the
 * owner's list opens with. Rows go with the user (CASCADE) and are preference
 * data, not business entities: no soft deletes, no audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('resource', 100);
            $table->string('name', 100);
            $table->json('filters')->nullable();
            $table->string('sort_column', 100)->nullable();
            $table->string('sort_direction', 4)->nullable();
            $table->string('search', 255)->nullable();
            $table->json('columns')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'resource', 'name'], 'saved_views_user_id_resource_name_unique');
            $table->index('resource', 'saved_views_resource_index');
            $table->index(['resource', 'is_shared'], 'saved_views_resource_is_shared_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
