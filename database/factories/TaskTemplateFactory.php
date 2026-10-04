<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoutineFrequency;
use App\Enums\TaskPriority;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A paused weekly routine (Sundays, due 17:00 the same day) with no assignee
 * and no assigner. Paused, because an active routine always carries the next
 * run day RoutineTaskService computes (a database CHECK holds it): tests that
 * need a running routine save one through the service, which is also where
 * the assignees are checked. Every column is present so the strict model
 * never reads a missing attribute.
 *
 * @extends Factory<TaskTemplate>
 */
final class TaskTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => null,
            'priority' => TaskPriority::Medium,
            'frequency' => RoutineFrequency::Weekly,
            'weekday' => 0,
            'day_of_month' => null,
            'due_time' => '17:00:00',
            'due_in_days' => 0,
            'is_active' => false,
            'next_run_on' => null,
            'last_run_on' => null,
            'assigned_by' => null,
        ];
    }

    public function monthly(int $dayOfMonth): static
    {
        return $this->state(fn (): array => [
            'frequency' => RoutineFrequency::Monthly,
            'weekday' => null,
            'day_of_month' => $dayOfMonth,
        ]);
    }

    public function assignedBy(User $assigner): static
    {
        return $this->state(fn (): array => ['assigned_by' => $assigner->getKey()]);
    }

    public function withAssignees(User ...$assignees): static
    {
        return $this->afterCreating(static function (TaskTemplate $template) use ($assignees): void {
            $template->assignees()->sync(array_map(static fn (User $user): int => (int) $user->getKey(), $assignees));
        });
    }
}
