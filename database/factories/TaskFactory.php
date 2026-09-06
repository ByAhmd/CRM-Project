<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RecurrenceFrequency;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * A pending task assigned to a fresh user, due in a few days, linked to no
 * record: tests set `lead_id`, `contact_id`, `account_id` or `deal_id`
 * explicitly. Every column is present so the strict model never reads a
 * missing attribute. The closed states write the guarded columns the way
 * TaskService does — production code never closes a task this way.
 *
 * @extends Factory<Task>
 */
final class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'kind' => TaskKind::Task,
            'status' => TaskStatus::Pending,
            'priority' => TaskPriority::Medium,
            'due_at' => now()->addDays(fake()->numberBetween(1, 14))->setTime(10, 0),
            'starts_at' => null,
            'ends_at' => null,
            'reminder_at' => null,
            'assignee_id' => User::factory(),
            'lead_id' => null,
            'contact_id' => null,
            'account_id' => null,
            'deal_id' => null,
            'recurrence_frequency' => RecurrenceFrequency::None,
            'recurrence_interval' => null,
            'recurrence_ends_at' => null,
            'series_id' => null,
            'created_by' => fn (array $attributes): mixed => $attributes['assignee_id'],
        ];
    }

    public function dueAt(Carbon $dueAt): static
    {
        return $this->state(fn (): array => ['due_at' => $dueAt]);
    }

    public function overdue(): static
    {
        return $this->state(fn (): array => ['due_at' => now()->subDay()]);
    }

    public function dueToday(): static
    {
        return $this->state(fn (): array => ['due_at' => now()->endOfDay()->subMinute()]);
    }

    public function unassigned(): static
    {
        return $this->state(fn (): array => ['assignee_id' => null, 'created_by' => User::factory()]);
    }

    public function recurring(RecurrenceFrequency $frequency, int $interval = 1, ?Carbon $endsAt = null): static
    {
        return $this->state(fn (): array => [
            'recurrence_frequency' => $frequency,
            'recurrence_interval' => $interval,
            'recurrence_ends_at' => $endsAt?->toDateString(),
        ]);
    }

    public function completed(): static
    {
        return $this->closedAs(TaskStatus::Completed);
    }

    public function cancelled(): static
    {
        return $this->closedAs(TaskStatus::Cancelled);
    }

    private function closedAs(TaskStatus $status): static
    {
        return $this->afterCreating(static function (Task $task) use ($status): void {
            Task::withoutWorkflowGuard(static fn (): bool => $task->forceFill([
                'status' => $status,
                'completed_at' => $status === TaskStatus::Completed ? now() : null,
            ])->saveQuietly());
        });
    }
}
