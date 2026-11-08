<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The project a lead belongs to (decision D-30): required, set when the
 * record is created and never edited afterwards, RESTRICT so a project in
 * use cannot be removed.
 *
 * Three steps in one table's migration: the column is added nullable, every
 * existing row is assigned to the default project (`2026_11_05_100002`), and
 * the column is then made NOT NULL — the type stays BIGINT UNSIGNED, which
 * MySQL and MariaDB both allow on a column that carries a foreign key. A
 * fresh install backfills nothing and ends with the same constraint. The
 * own index serves the current-project scope on every list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->after('id')->constrained('projects')->restrictOnDelete();

            $table->index('project_id', 'leads_project_id_index');
        });

        $default = DB::table('projects')->where('is_default', true)->orderBy('id')->value('id')
            ?? DB::table('projects')->orderBy('id')->value('id');

        if ($default !== null) {
            DB::table('leads')->whereNull('project_id')->update(['project_id' => $default]);
        }

        Schema::table('leads', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_project_id_index');
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
