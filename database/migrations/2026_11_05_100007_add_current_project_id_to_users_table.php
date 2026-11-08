<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The project a user is working in (decision D-30): chosen from the project
 * switcher in the Sales top bar and remembered across devices and sessions.
 * Null — and a reference to a project since deactivated — means the default
 * project (Services\Projects\CurrentProject). A removed project clears it
 * (nullOnDelete). A preference, not a business fact: written only by
 * CurrentProject::switch(), not fillable, not audited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('current_project_id')->nullable()->after('team_id')->constrained('projects')->nullOnDelete();

            $table->index('current_project_id', 'users_current_project_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_current_project_id_index');
            $table->dropConstrainedForeignId('current_project_id');
        });
    }
};
