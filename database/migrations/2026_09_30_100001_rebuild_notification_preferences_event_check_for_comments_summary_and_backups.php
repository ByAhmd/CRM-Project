<?php

declare(strict_types=1);

use App\Enums\NotificationEvent;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the CHECK on notification_preferences.event for three new
 * NotificationEvent cases — task_comment (D-17), weekly_summary (D-18) and
 * backup_failed (D-16), all decided on 2026-09-30 (A-4): adding enum cases
 * means dropping and recreating the constraint through EnumCheck, which
 * spells the DROP per engine (D-1, MySQL 8 vs MariaDB).
 *
 * down() removes any rows of the new cases first (the narrower constraint
 * would otherwise refuse them) and recreates the CHECK from the event list
 * before these decisions, spelled literally because the enum in code
 * already knows the new cases.
 */
return new class extends Migration
{
    /** @var list<string> The event values before D-16, D-17 and D-18. */
    private const array PREVIOUS_EVENTS = [
        'record_assigned',
        'task_reminder',
        'task_overdue',
        'task_completed',
        'task_progress',
        'deal_stage_changed',
        'deal_closed',
        'lead_converted',
        'lead_stale',
        'note_mention',
    ];

    public function up(): void
    {
        EnumCheck::drop('notification_preferences', 'event');
        EnumCheck::apply('notification_preferences', 'event', NotificationEvent::class);
    }

    public function down(): void
    {
        DB::table('notification_preferences')
            ->whereIn('event', [
                NotificationEvent::TaskComment->value,
                NotificationEvent::WeeklySummary->value,
                NotificationEvent::BackupFailed->value,
            ])
            ->delete();

        EnumCheck::drop('notification_preferences', 'event');

        $values = implode(', ', array_map(
            static fn (string $value): string => DB::getPdo()->quote($value),
            self::PREVIOUS_EVENTS,
        ));

        DB::statement(sprintf(
            'ALTER TABLE `notification_preferences` ADD CONSTRAINT `%s` CHECK (`event` IN (%s))',
            EnumCheck::name('notification_preferences', 'event'),
            $values,
        ));
    }
};
