<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of run an import was, and whether it was undone (decision D-29).
 *
 * `dry_run`: the run read and validated the file without writing a record
 * (stamped from the modal's «check only» option when the run starts); it can
 * never be undone. The undo columns are written only by ImportUndoService:
 * `undo_requested_at` when the actor confirms (the run shows «undoing» and
 * the page refreshes itself), `undone_at`, `undone_by` and the two counts
 * when the queued job finishes — the records it deleted, and the records it
 * kept because they were updated rather than created, touched since the
 * import, out of the actor's reach or already deleted. A run is undone once.
 *
 * DATETIME like every other moment the design types; `undone_by` is a user
 * reference (nullOnDelete, and users are only ever soft-deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            $table->boolean('dry_run')->default(false)->after('importer');
            $table->dateTime('undo_requested_at')->nullable()->after('completed_at');
            $table->dateTime('undone_at')->nullable()->after('undo_requested_at');
            $table->foreignId('undone_by')->nullable()->after('undone_at')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('undone_rows')->default(0)->after('undone_by');
            $table->unsignedInteger('kept_rows')->default(0)->after('undone_rows');

            $table->index('undone_by', 'imports_undone_by_index');
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            $table->dropForeign(['undone_by']);
            $table->dropIndex('imports_undone_by_index');
            $table->dropColumn(['dry_run', 'undo_requested_at', 'undone_at', 'undone_by', 'undone_rows', 'kept_rows']);
        });
    }
};
