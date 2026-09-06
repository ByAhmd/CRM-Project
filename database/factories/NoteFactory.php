<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A note with an author and no subject: tests set `lead_id`, `contact_id`,
 * `account_id` or `deal_id` explicitly (or go through NoteService, which
 * derives them).
 *
 * @extends Factory<Note>
 */
final class NoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'body' => fake()->paragraph(),
            'author_id' => User::factory(),
            'is_pinned' => false,
        ];
    }

    public function pinned(): static
    {
        return $this->state(fn (): array => ['is_pinned' => true]);
    }
}
