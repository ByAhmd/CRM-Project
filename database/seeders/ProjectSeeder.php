<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BadgeColor;
use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * Seeds the default project (decision D-30): «المشروع الرئيسي» / "Main
 * project", the one every record is assigned to until the owner adds
 * others.
 *
 * Idempotent and deferential: created only while no project exists at all,
 * so a renamed, recoloured or reordered default is left untouched on the next
 * deploy, and so is an install whose owner made another project the default.
 * The release's data migration (`2026_11_05_100002`) creates the same row
 * before the record columns are backfilled; this seeder covers a database
 * seeded after a manual clean-up.
 */
final class ProjectSeeder extends Seeder
{
    public const string NAME_AR = 'المشروع الرئيسي';

    public const string NAME_EN = 'Main project';

    public function run(): void
    {
        if (Project::query()->exists()) {
            return;
        }

        Project::query()->create([
            'name_ar' => self::NAME_AR,
            'name_en' => self::NAME_EN,
            'color' => BadgeColor::Primary,
            'is_active' => true,
            'is_default' => true,
            'sort' => 10,
        ]);
    }
}
