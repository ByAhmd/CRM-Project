<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The project a contact belongs to (decision D-30): required, set when the
 * record is created and never edited afterwards, RESTRICT so a project in
 * use cannot be removed. Added nullable, backfilled to the default project
 * (`2026_11_05_100002`), then made NOT NULL — see the leads migration for
 * why the three steps are safe on MySQL and MariaDB alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->after('id')->constrained('projects')->restrictOnDelete();

            $table->index('project_id', 'contacts_project_id_index');
        });

        $default = DB::table('projects')->where('is_default', true)->orderBy('id')->value('id')
            ?? DB::table('projects')->orderBy('id')->value('id');

        if ($default !== null) {
            DB::table('contacts')->whereNull('project_id')->update(['project_id' => $default]);
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex('contacts_project_id_index');
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
