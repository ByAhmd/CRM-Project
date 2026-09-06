<?php

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks and follow-ups (decisions A-10, D-4).
 *
 * A task belongs to an assignee (the owner column for visibility purposes)
 * and optionally to a lead, a contact, an account or a deal — a task about
 * nothing is a personal to-do. `kind`, `status`, `priority` and
 * `recurrence_frequency` are CHECK-constrained to their enums; `status`,
 * `completed_at` and the two notification stamps are written by the task
 * services only (guarded on the model). `reminder_sent_at` and
 * `overdue_notified_at` make the scheduler commands idempotent (D-1: no
 * persistent worker, the scheduler drains them). A recurring task points at
 * the first task of its series through `series_id`; soft deletes keep the
 * row restorable (D-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('kind', 32)->default(TaskKind::Task->value);
            $table->string('status', 32)->default(TaskStatus::Pending->value);
            $table->string('priority', 32)->default(TaskPriority::Medium->value);
            $table->dateTime('due_at')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('reminder_at')->nullable();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->dateTime('overdue_notified_at')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->string('recurrence_frequency', 32)->default(RecurrenceFrequency::None->value);
            $table->unsignedTinyInteger('recurrence_interval')->nullable();
            $table->date('recurrence_ends_at')->nullable();
            $table->foreignId('series_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('kind', 'tasks_kind_index');
            $table->index('status', 'tasks_status_index');
            $table->index('priority', 'tasks_priority_index');
            $table->index('due_at', 'tasks_due_at_index');
            $table->index('reminder_at', 'tasks_reminder_at_index');
            $table->index('assignee_id', 'tasks_assignee_id_index');
            $table->index(['assignee_id', 'status', 'due_at'], 'tasks_assignee_id_status_due_at_index');
            $table->index('lead_id', 'tasks_lead_id_index');
            $table->index('contact_id', 'tasks_contact_id_index');
            $table->index('account_id', 'tasks_account_id_index');
            $table->index('deal_id', 'tasks_deal_id_index');
            $table->index('series_id', 'tasks_series_id_index');
        });

        EnumCheck::apply('tasks', 'kind', TaskKind::class);
        EnumCheck::apply('tasks', 'status', TaskStatus::class);
        EnumCheck::apply('tasks', 'priority', TaskPriority::class);
        EnumCheck::apply('tasks', 'recurrence_frequency', RecurrenceFrequency::class);
    }

    public function down(): void
    {
        if (Schema::hasTable('tasks')) {
            EnumCheck::drop('tasks', 'recurrence_frequency');
            EnumCheck::drop('tasks', 'priority');
            EnumCheck::drop('tasks', 'status');
            EnumCheck::drop('tasks', 'kind');
        }

        Schema::dropIfExists('tasks');
    }
};
