<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops `saved_views` — the saved-views feature is withdrawn (A-8/A-18,
 * amended 2026-09-17).
 *
 * The owner found the three list-page buttons («العروض» / «حفظ العرض» /
 * «مسح العرض») of no use, so the whole feature goes: the actions, the model,
 * the service, the policy, the `saved_view.share` permission and this table.
 * Filament still persists each list's filters, sort and search in the session,
 * which is what the lists actually opened with.
 *
 * The rows are personal preference data, never business records (no soft
 * deletes, no audit), so they are dropped rather than archived. The creating
 * migration (2026_09_06_160001) stays where it is — databases mid-history have
 * already run it — and this `down()` recreates the table exactly as that
 * migration did, so a rollback past this point lands on the same schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('saved_views');
    }

    public function down(): void
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
};
