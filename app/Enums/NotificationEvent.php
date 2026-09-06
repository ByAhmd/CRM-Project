<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The events a user can be notified about (plan section 3.6). One row per
 * user and event in notification_preferences decides the channels; the
 * database channel defaults to on, mail to off (opt-in, CLAUDE.md section 3).
 */
enum NotificationEvent: string implements HasLabel
{
    case RecordAssigned = 'record_assigned';
    case TaskReminder = 'task_reminder';
    case TaskOverdue = 'task_overdue';
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

    /** Mail is opt-in for every event. */
    public function mailByDefault(): bool
    {
        return false;
    }
}
