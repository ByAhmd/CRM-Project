<?php

declare(strict_types=1);

use App\Support\Database\TimestampRange;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead workflow moments become DATETIME (DATABASE_DESIGN.md, leads table).
 *
 * scored_at, qualified_at, converted_at, last_activity_at and
 * stale_notified_at were created as TIMESTAMP: a range that ends in 2038 and
 * values converted through the MySQL session time zone, which nothing pins
 * while the application writes organisation-time wall clocks (D-8,
 * Asia/Riyadh). The design and the tasks, activities and custom-field tables
 * already use DATETIME. MODIFY keeps every value as the session reads it and
 * keeps the existing indexes.
 *
 * down() converts back to TIMESTAMP, which ends at 2038-01-19
 * 03:14:07 UTC; it refuses before any DDL when a value falls outside that
 * range, naming the rows (App\Support\Database\TimestampRange).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array COLUMNS = ['scored_at', 'qualified_at', 'converted_at', 'last_activity_at', 'stale_notified_at'];

    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->dateTime($column)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        TimestampRange::refuseValuesOutside('leads', self::COLUMNS);

        Schema::table('leads', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->timestamp($column)->nullable()->change();
            }
        });
    }
};
