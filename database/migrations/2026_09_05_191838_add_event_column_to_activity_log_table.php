<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the model event name (`created`, `updated`, …) to the audit ledger
 * (D-13), as published by spatie/laravel-activitylog v4. Kept to the
 * package's schema; anonymous like every other migration in the project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))->table(config('activitylog.table_name'), function (Blueprint $table): void {
            $table->string('event')->nullable()->after('subject_type');
        });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))->table(config('activitylog.table_name'), function (Blueprint $table): void {
            $table->dropColumn('event');
        });
    }
};
