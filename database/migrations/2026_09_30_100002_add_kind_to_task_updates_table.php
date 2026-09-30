<?php

declare(strict_types=1);

use App\Enums\TaskUpdateKind;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comments on tasks (decision D-17): a task's log becomes one thread of
 * progress entries and comments, told apart by `kind` — `progress` for a
 * start, a progress update, a completion or a reopening (D-14 amendment,
 * 2026-09-28), `comment` for a comment from anyone who may view the task.
 *
 * Every row written before this migration is a progress entry, so the column
 * arrives NOT NULL with `progress` as its default; the code enum is
 * CHECK-constrained (A-4). The `(task_id, kind)` index answers "who commented
 * on this task before" — the recipients of a new comment — without reading
 * the whole thread; the thread itself keeps using `(task_id, created_at)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_updates', function (Blueprint $table): void {
            $table->string('kind', 32)->default(TaskUpdateKind::Progress->value)->after('user_id');
            $table->index(['task_id', 'kind'], 'task_updates_task_id_kind_index');
        });

        EnumCheck::apply('task_updates', 'kind', TaskUpdateKind::class);
    }

    public function down(): void
    {
        EnumCheck::drop('task_updates', 'kind');

        Schema::table('task_updates', function (Blueprint $table): void {
            $table->dropIndex('task_updates_task_id_kind_index');
            $table->dropColumn('kind');
        });
    }
};
