<?php

declare(strict_types=1);

use App\Enums\BadgeColor;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHECK constraint on activity_types.color (decision A-4, CLAUDE.md
 * section 3: code-enum columns are VARCHAR(32) guarded by EnumCheck).
 *
 * The model casts the colour to BadgeColor, like lead_statuses, pipeline
 * stages and tags, but the column was created as a bare VARCHAR(20), so a
 * seeder, an import or a tinker session could store a colour the badge
 * renderer does not know. The column is widened to the convention's size and
 * constrained to the enum's cases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_types', function (Blueprint $table): void {
            $table->string('color', 32)->change();
        });

        EnumCheck::apply('activity_types', 'color', BadgeColor::class);
    }

    public function down(): void
    {
        EnumCheck::drop('activity_types', 'color');

        Schema::table('activity_types', function (Blueprint $table): void {
            $table->string('color', 20)->change();
        });
    }
};
