<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoutineFrequency;
use App\Enums\TaskPriority;
use App\Models\Concerns\AuditsAsLookup;
use Database\Factories\TaskTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A routine task (decision D-22): a recurring task template that hands the
 * same work to one or more assignees every week on a chosen day, or every
 * month on a chosen day 1–28.
 *
 * Configuration, not a business entity: no soft deletes, and the tasks a
 * routine created outlive it (tasks.template_id is cleared). Every write goes
 * through RoutineTaskService — the schedule fields are normalised there, the
 * assignees checked against the actor's assignment reach, and the three
 * stamps below are written with forceFill only, never fillable:
 * `assigned_by` (who last saved the routine — the tasks are handed out in
 * their name; pausing and resuming do not change it), `next_run_on` (the
 * organisation day the next tasks are
 * produced; set exactly while the routine is active) and `last_run_on` (the
 * day produced last). Audited as configuration (the `settings` channel, like
 * the other configurable rows) with an explicit attribute whitelist; the
 * hourly advance of the run days is bookkeeping and stays out of the ledger.
 * Its checklist (D-25) is a list of child rows the service replaces and
 * records on the routine, like the assignees.
 *
 * @property TaskPriority $priority
 * @property RoutineFrequency $frequency
 * @property ?int $weekday
 * @property ?int $day_of_month
 * @property string $due_time
 * @property int $due_in_days
 * @property bool $is_active
 * @property ?Carbon $next_run_on
 * @property ?Carbon $last_run_on
 * @property ?int $assigned_by
 */
#[Fillable(['title', 'description', 'priority', 'frequency', 'weekday', 'day_of_month', 'due_time', 'due_in_days', 'is_active'])]
final class TaskTemplate extends Model
{
    use AuditsAsLookup;

    /** @use HasFactory<TaskTemplateFactory> */
    use HasFactory;

    /** @var list<string> */
    protected static array $recordEvents = ['created', 'updated', 'deleted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'frequency' => RoutineFrequency::class,
            'weekday' => 'integer',
            'day_of_month' => 'integer',
            'due_in_days' => 'integer',
            'is_active' => 'boolean',
            'next_run_on' => 'date',
            'last_run_on' => 'date',
        ];
    }

    /**
     * The ledger whitelist: what a person sets. The run days and the assigner
     * stamp are left out; assignee changes are recorded by RoutineTaskService.
     *
     * @return list<string>
     */
    public static function auditedAttributes(): array
    {
        return ['title', 'description', 'priority', 'frequency', 'weekday', 'day_of_month', 'due_time', 'due_in_days', 'is_active'];
    }

    /**
     * The users who receive a task on every scheduled day.
     *
     * @return BelongsToMany<User, $this>
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_template_user')->orderBy('users.name')->orderBy('users.id');
    }

    /**
     * Who last saved the routine — kept when their account is deleted, so
     * the routine keeps naming who set it up.
     *
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by')->withTrashed();
    }

    /**
     * The checklist copied, unticked, into every task the routine creates
     * (D-25), in its order.
     *
     * @return HasMany<TaskTemplateChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskTemplateChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The tasks this routine created, newest first.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'template_id')->orderByDesc('id');
    }
}
