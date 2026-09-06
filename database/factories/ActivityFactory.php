<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivityKind;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A logged call against a fresh lead. The type is the seeded system row of
 * the kind when it exists and a factory row otherwise; `kind` is copied
 * from it the way ActivityRecorder does. Production code never creates
 * activities this way — the recorder is the only writer.
 *
 * @extends Factory<Activity>
 */
final class ActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'activity_type_id' => fn (): int => $this->typeId(ActivityKind::Call),
            'kind' => ActivityKind::Call,
            'subject' => fake()->sentence(4),
            'body' => fake()->optional()->paragraph(),
            'occurred_at' => now()->subMinutes(fake()->numberBetween(1, 600)),
            'lead_id' => Lead::factory(),
        ];
    }

    public function ofKind(ActivityKind $kind): static
    {
        return $this->state(fn (): array => [
            'activity_type_id' => $this->typeId($kind),
            'kind' => $kind,
        ]);
    }

    private function typeId(ActivityKind $kind): int
    {
        $id = ActivityType::query()->where('kind', $kind->value)->where('is_system', true)->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return (int) ActivityType::factory()->system($kind)->create()->getKey();
    }
}
