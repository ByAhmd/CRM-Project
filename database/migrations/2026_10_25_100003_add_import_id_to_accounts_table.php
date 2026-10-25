<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The import run that created the account (decision D-29).
 *
 * Stamped by the importer on every record a run creates — never on a
 * duplicate it updates — so the run can be undone later: ImportUndoService
 * soft-deletes the records that carry its id and that nobody has touched
 * since. A dry run creates nothing and stamps nothing. The import row is
 * pruned after Import::RETENTION_DAYS; the records outlive it with the link
 * cleared (nullOnDelete), and a pruned run can no longer be undone. Not
 * fillable, not audited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->foreignId('import_id')->nullable()->constrained('imports')->nullOnDelete();

            $table->index('import_id', 'accounts_import_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropForeign(['import_id']);
            $table->dropIndex('accounts_import_id_index');
            $table->dropColumn('import_id');
        });
    }
};
