<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The project an import run wrote into (decision D-30): the «المشروع»
 * option of the import modal, stamped on the history row by the
 * ImportStarted listener when the run starts, so the history says which
 * project each file went to. Nullable: runs from before D-30 have none, and
 * a removed project clears it (nullOnDelete) while the run stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->after('importer')->constrained('projects')->nullOnDelete();

            $table->index('project_id', 'imports_project_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            $table->dropIndex('imports_project_id_index');
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
