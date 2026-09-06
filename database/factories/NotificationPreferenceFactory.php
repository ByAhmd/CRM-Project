<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A preference row with the enum defaults (bell on, mail off); tests pick the
 * event and flip the channels they exercise.
 *
 * @extends Factory<NotificationPreference>
 */
final class NotificationPreferenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event' => NotificationEvent::RecordAssigned,
            'database' => true,
            'mail' => false,
        ];
    }

    public function ofEvent(NotificationEvent $event): static
    {
        return $this->state(fn (): array => ['event' => $event]);
    }

    public function withMail(): static
    {
        return $this->state(fn (): array => ['mail' => true]);
    }

    public function withoutDatabase(): static
    {
        return $this->state(fn (): array => ['database' => false]);
    }
}
