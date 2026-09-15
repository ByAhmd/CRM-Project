<?php

declare(strict_types=1);

use App\Support\Database\TimestampRange;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * notes.edited_at becomes DATETIME (DATABASE_DESIGN.md notes table,
 * decision A-10).
 *
 * The moment a note body was last edited was stored as TIMESTAMP: a range
 * that ends in 2038 and values converted through the unpinned MySQL session
 * time zone. MODIFY keeps every value as the session reads it.
 *
 * down() converts back to TIMESTAMP, which ends at 2038-01-19
 * 03:14:07 UTC; it refuses before any DDL when a value falls outside that
 * range, naming the rows (App\Support\Database\TimestampRange).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->dateTime('edited_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        TimestampRange::refuseValuesOutside('notes', ['edited_at']);

        Schema::table('notes', function (Blueprint $table): void {
            $table->timestamp('edited_at')->nullable()->change();
        });
    }
};
