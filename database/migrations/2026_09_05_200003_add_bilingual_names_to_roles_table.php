<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bilingual display names on spatie's roles table (decisions D-3, D-5).
 *
 * `name` stays the machine key (e.g. sales_manager) that code and the
 * permission cache use; name_ar / name_en are what the interface shows, so a
 * role created at runtime by a super admin is as bilingual as a seeded one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('name_ar', 100)->after('name');
            $table->string('name_en', 100)->after('name_ar');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn(['name_ar', 'name_en']);
        });
    }
};
