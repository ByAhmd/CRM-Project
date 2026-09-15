<?php

declare(strict_types=1);

use App\Support\Database\TimestampRange;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deal close and activity moments become DATETIME (DATABASE_DESIGN.md,
 * deals table).
 *
 * won_at, lost_at and last_activity_at were created as TIMESTAMP: a range
 * that ends in 2038 and values converted through the unpinned MySQL session
 * time zone. The design types them as DATETIME like every other business
 * moment. MODIFY keeps every value as the session reads it and keeps the
 * existing indexes.
 *
 * down() converts back to TIMESTAMP, which ends at 2038-01-19
 * 03:14:07 UTC; it refuses before any DDL when a value falls outside that
 * range, naming the rows (App\Support\Database\TimestampRange).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array COLUMNS = ['won_at', 'lost_at', 'last_activity_at'];

    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->dateTime($column)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        TimestampRange::refuseValuesOutside('deals', self::COLUMNS);

        Schema::table('deals', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->timestamp($column)->nullable()->change();
            }
        });
    }
};
