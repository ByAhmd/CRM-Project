<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales teams (decision D-4).
 *
 * A user belongs to at most one team (users.team_id); a team may name a
 * manager. Team membership is what "view team" visibility resolves against.
 * Teams are soft-deleted so historical ownership stays explainable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('name_ar', 'teams_name_ar_unique');
            $table->unique('name_en', 'teams_name_en_unique');
            $table->index(['is_active', 'sort'], 'teams_is_active_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
