<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The events a user can be notified about (plan section 3.6). One row per
 * user and event in notification_preferences decides the channels; the
 * database channel defaults to on, mail to off (opt-in, CLAUDE.md section 3)
 * except for RecordAssigned, whose mail defaults to on (D-14) — the per-user
 * opt-out and the real-transport gate (D-10) still apply.
 */
enum NotificationEvent: string implements HasLabel
{
    case RecordAssigned = 'record_assigned';
    case TaskReminder = 'task_reminder';
    case TaskOverdue = 'task_overdue';
    case TaskCompleted = 'task_completed';
    case TaskProgress = 'task_progress';
    case DealStageChanged = 'deal_stage_changed';
    case DealClosed = 'deal_closed';
    case LeadConverted = 'lead_converted';
    case LeadStale = 'lead_stale';
    case NoteMention = 'note_mention';

    public function getLabel(): string
    {
        return __('enums.notification_event.'.$this->value);
    }

    /** The database (bell) channel is on unless the user switched it off. */
    public function databaseByDefault(): bool
    {
        return true;
    }

    /** Mail is opt-in for every event except an assignment, which is opt-out (D-14). */
    public function mailByDefault(): bool
    {
        return $this === self::RecordAssigned;
    }
}
