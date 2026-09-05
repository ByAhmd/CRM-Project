<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organisation settings editable at runtime (decisions D-2, D-8).
 *
 * One row per key; the value is JSON so a setting may be a string, number,
 * boolean or list. Typed access goes through SettingsRepository — nothing reads
 * this table directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->string('group', 50);
            $table->timestamps();

            $table->unique('key', 'settings_key_unique');
            $table->index('group', 'settings_group_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
