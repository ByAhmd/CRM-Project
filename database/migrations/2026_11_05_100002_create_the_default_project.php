<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration for decision D-30: the one project every install starts
 * with, «المشروع الرئيسي» / "Main project", flagged as the default.
 *
 * The migrations that follow give every lead, contact, account and deal a
 * required `project_id` and backfill the rows that exist to the default
 * project, so it must exist before they run — on a production install with
 * years of records as on a fresh one (the test databases included). It is
 * created only while the table is empty: an install that already holds
 * projects keeps its own default. ProjectSeeder does the same on every
 * `db:seed`, so neither path ever creates a second one; the owner renames
 * the project in Administration → Settings → Projects.
 *
 * down() removes the row only while it is the only project and nothing
 * references it (the dependent columns are dropped first on a rollback).
 */
return new class extends Migration
{
    private const string NAME_AR = 'المشروع الرئيسي';

    private const string NAME_EN = 'Main project';

    public function up(): void
    {
        if (DB::table('projects')->exists()) {
            return;
        }

        $now = now();

        DB::table('projects')->insert([
            'name_ar' => self::NAME_AR,
            'name_en' => self::NAME_EN,
            'color' => 'primary',
            'is_active' => true,
            'is_default' => true,
            'sort' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (DB::table('projects')->count() !== 1) {
            return;
        }

        DB::table('projects')
            ->where('is_default', true)
            ->where('name_en', self::NAME_EN)
            ->delete();
    }
};
