<?php

declare(strict_types=1);

use App\Support\Database\TimestampRange;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * deal_stage_logs.changed_at becomes DATETIME NOT NULL (DATABASE_DESIGN.md,
 * decision A-5).
 *
 * The append-only stage history was stamped in a TIMESTAMP column: a range
 * that ends in 2038 and values converted through the unpinned MySQL session
 * time zone. MODIFY keeps every value as the session reads it and keeps both
 * indexes on the column.
 *
 * down() converts back to TIMESTAMP, which ends at 2038-01-19
 * 03:14:07 UTC; it refuses before any DDL when a value falls outside that
 * range, naming the rows (App\Support\Database\TimestampRange).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_stage_logs', function (Blueprint $table): void {
            $table->dateTime('changed_at')->change();
        });
    }

    public function down(): void
    {
        TimestampRange::refuseValuesOutside('deal_stage_logs', ['changed_at']);

        Schema::table('deal_stage_logs', function (Blueprint $table): void {
            $table->timestamp('changed_at')->change();
        });
    }
};
