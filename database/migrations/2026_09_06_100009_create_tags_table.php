<?php

declare(strict_types=1);

use App\Enums\BadgeColor;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags (decision A-4).
 *
 * Free-form bilingual labels an administrator defines once and users attach
 * to leads, contacts, accounts and deals through the `taggables` pivot. The
 * colour is a Filament palette name constrained at the database. Tags are
 * not soft-deleted: removing one detaches it everywhere (pivot cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('color', 20)->default(BadgeColor::Gray->value);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name_ar', 'tags_name_ar_unique');
            $table->unique('name_en', 'tags_name_en_unique');
            $table->index('is_active', 'tags_is_active_index');
        });

        EnumCheck::apply('tags', 'color', BadgeColor::class);
    }

    public function down(): void
    {
        if (Schema::hasTable('tags')) {
            EnumCheck::drop('tags', 'color');
        }

        Schema::dropIfExists('tags');
    }
};
