<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index on tasks.starts_at (plan section 8, decision D-12).
 *
 * The calendar feed loads timed tasks by the visible range and compares
 * starts_at against the range end; due_at and reminder_at were indexed,
 * starts_at was not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->index('starts_at', 'tasks_starts_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('tasks_starts_at_index');
        });
    }
};
