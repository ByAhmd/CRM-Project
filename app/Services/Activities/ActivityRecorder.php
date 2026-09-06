<?php

declare(strict_types=1);

namespace App\Services\Activities;

use App\Enums\ActivityDirection;
use App\Enums\ActivityKind;
use App\Enums\ActivityLogEvent;
use App\Exceptions\Activities\InvalidActivityException;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only way an activity is written or removed (decision A-10).
 *
 * - the type must be active and its kind is copied onto the row;
 * - direction is kept only for kinds that have one (calls, emails) and
 *   duration only for kinds that have one (calls, meetings);
 * - occurred_at defaults to now and the owner to the actor;
 * - the linked lead and deal have last_activity_at moved forward to the
 *   activity's occurred_at (never backwards: a backdated activity does not
 *   hide a later one);
 * - creation and deletion are written to the audit ledger.
 *
 * recordSystem() is for events the application writes itself (a conversion,
 * a sent email, a completed task): it resolves the seeded system type of the
 * kind and does not require that type to be active, since deactivating a
 * type only hides it from the user picker.
 */
final class ActivityRecorder
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function record(
        ActivitySubject $subject,
        ActivityType $type,
        User $actor,
        string $subjectLine,
        ?string $body = null,
        ?Carbon $occurredAt = null,
        ?ActivityDirection $direction = null,
        ?int $durationMinutes = null,
        ?string $outcome = null,
        ?User $owner = null,
        ?array $payload = null,
    ): Activity {
        if (! $type->is_active) {
            throw InvalidActivityException::inactiveType();
        }

        return $this->store($subject, $type, $actor, $subjectLine, $body, $occurredAt, $direction, $durationMinutes, $outcome, $owner, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  ?int  $taskId  The task a completion entry points back at (Activity.task_id).
     */
    public function recordSystem(ActivitySubject $subject, ActivityKind $kind, User $actor, string $subjectLine, array $payload = [], ?int $taskId = null): Activity
    {
        $type = ActivityType::query()
            ->where('kind', $kind->value)
            ->where('is_system', true)
            ->orderBy('id')
            ->first();

        if ($type === null) {
            throw InvalidActivityException::systemTypeMissing();
        }

        return $this->store($subject, $type, $actor, $subjectLine, payload: $payload === [] ? null : $payload, taskId: $taskId);
    }

    public function delete(Activity $activity, User $actor): void
    {
        DB::transaction(function () use ($activity, $actor): void {
            $activity->loadMissing(['lead', 'contact', 'account', 'deal']);

            $this->audit->record(ActivityLogEvent::ActivityDeleted, $activity, $actor, [
                'subject_label' => $activity->subject,
                'kind' => $activity->kind->value,
                'occurred_at' => $activity->occurred_at->toIso8601String(),
                'lead_name' => $activity->lead?->full_name,
                'contact_name' => $activity->contact?->full_name,
                'account_name' => $activity->account?->name,
                'deal_title' => $activity->deal?->title,
            ]);

            $activity->delete();
        });
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function store(
        ActivitySubject $subject,
        ActivityType $type,
        User $actor,
        string $subjectLine,
        ?string $body = null,
        ?Carbon $occurredAt = null,
        ?ActivityDirection $direction = null,
        ?int $durationMinutes = null,
        ?string $outcome = null,
        ?User $owner = null,
        ?array $payload = null,
        ?int $taskId = null,
    ): Activity {
        $kind = $type->kind;
        $occurredAt ??= now();
        $owner ??= $actor;
        $body = trim((string) $body);
        $outcome = trim((string) $outcome);

        return DB::transaction(function () use ($subject, $type, $kind, $actor, $subjectLine, $body, $occurredAt, $direction, $durationMinutes, $outcome, $owner, $payload, $taskId): Activity {
            $activity = Activity::query()->create([
                'activity_type_id' => $type->getKey(),
                'kind' => $kind,
                'subject' => trim($subjectLine),
                'body' => $body === '' ? null : $body,
                'direction' => $kind->hasDirection() ? $direction : null,
                'occurred_at' => $occurredAt,
                'duration_minutes' => $kind->hasDuration() ? $durationMinutes : null,
                'outcome' => $outcome === '' ? null : $outcome,
                ...$subject->foreignKeys(),
                'task_id' => $taskId,
                'owner_id' => $owner->getKey(),
                'created_by' => $actor->getKey(),
                'payload' => $payload,
            ]);

            $this->touchLastActivity($subject->lead, $occurredAt);
            $this->touchLastActivity($subject->deal, $occurredAt);

            $this->audit->record(ActivityLogEvent::ActivityCreated, $activity, $actor, [
                'subject_label' => $activity->subject,
                'kind' => $kind->value,
                'activity_type_id' => $type->getKey(),
                'occurred_at' => $occurredAt->toIso8601String(),
                'owner_id' => $owner->getKey(),
                'owner_name' => $owner->name,
                ...$subject->labels(),
            ]);

            return $activity;
        });
    }

    /**
     * last_activity_at is not a workflow-guarded column: it is a derived
     * timestamp, so it is written quietly without an audit row of its own.
     * Moving a lead's stamp forward also clears stale_notified_at, so the
     * stale-lead pass may warn the owner again if the lead goes quiet later.
     */
    private function touchLastActivity(Lead|Deal|null $record, Carbon $occurredAt): void
    {
        if ($record === null) {
            return;
        }

        $current = $record->last_activity_at;

        if ($current !== null && $current->greaterThanOrEqualTo($occurredAt)) {
            return;
        }

        $attributes = ['last_activity_at' => $occurredAt];

        if ($record instanceof Lead) {
            $attributes['stale_notified_at'] = null;
        }

        $record->forceFill($attributes)->saveQuietly();
    }
}
