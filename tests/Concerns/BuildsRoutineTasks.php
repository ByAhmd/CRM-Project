<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\RoutineFrequency;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\Tasks\RoutineTaskService;
use Carbon\CarbonImmutable;

/**
 * Routine task fixtures shared by the D-22 tests: a routine is always saved
 * through RoutineTaskService — the only path that checks its assignees and
 * computes its next run day — and the clock is set in the organisation's
 * default timezone, so each test reads as a Riyadh calendar.
 */
trait BuildsRoutineTasks
{
    /**
     * A routine $actor saves for the given assignees: by default weekly on
     * Sundays, due 17:00 the same day, active.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function routine(User $actor, array $attributes = [], User ...$assignees): TaskTemplate
    {
        return app(RoutineTaskService::class)->save([
            'title' => 'Weekly stock count',
            'frequency' => RoutineFrequency::Weekly->value,
            'weekday' => 0,
            'assignees' => array_map(static fn (User $assignee): int => (int) $assignee->getKey(), $assignees),
            ...$attributes,
        ], $actor);
    }

    /** The routine as stored now. */
    protected function stored(TaskTemplate $routine): TaskTemplate
    {
        return TaskTemplate::query()->findOrFail($routine->getKey());
    }

    /** Moves the clock to a moment given in Riyadh wall time. */
    protected function travelToRiyadh(string $moment): void
    {
        $this->travelTo(CarbonImmutable::parse($moment, 'Asia/Riyadh'));
    }
}
