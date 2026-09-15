<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens accounts.phone_normalized from VARCHAR(20) to VARCHAR(32) (decision A-11).
 *
 * The form and the importer accept a phone of up to 30 characters, and
 * App\Support\Normalizer keeps every digit of an international number behind a
 * leading "+", so a long number the form accepts produced up to 31 characters
 * and strict MySQL refused the insert (error 1406). 32 holds any accepted
 * input. The duplicate-detection index is kept: MODIFY preserves it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('phone_normalized', 32)->nullable()->change();
        });
    }

    /**
     * A normalised number longer than the old width cannot be kept, so it is
     * cleared before narrowing; it is derived data, recomputed on the next
     * save of the record.
     */
    public function down(): void
    {
        DB::table('accounts')->whereRaw('CHAR_LENGTH(phone_normalized) > 20')->update(['phone_normalized' => null]);

        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('phone_normalized', 20)->nullable()->change();
        });
    }
};
