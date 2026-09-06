<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An attachment row (a PDF on a lead by default). The factory writes the
 * row only: tests that stream or delete files store them through
 * AttachmentStorage on a faked disk.
 *
 * @extends Factory<Attachment>
 */
final class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'uuid' => $uuid,
            'attachable_type' => (new Lead)->getMorphClass(),
            'attachable_id' => Lead::factory(),
            'disk' => (string) config('crm.attachments.disk'),
            'path' => fn (array $attributes): string => sprintf('crm/lead/%s/%s.pdf', $attributes['attachable_id'], $uuid),
            'original_name' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1024, 512 * 1024),
            'description' => null,
        ];
    }

    /** Attach the row to the given subject instead of a fresh lead. */
    public function forSubject(Model $subject): self
    {
        return $this->state(fn (array $attributes): array => [
            'attachable_type' => $subject->getMorphClass(),
            'attachable_id' => $subject->getKey(),
            'path' => sprintf('crm/%s/%s/%s.pdf', Str::lower(class_basename($subject)), $subject->getKey(), $attributes['uuid']),
        ]);
    }
}
