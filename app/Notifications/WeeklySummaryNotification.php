<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Filament\Pages\System\Backups;
use App\Filament\Pages\TasksBoard;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\User;
use App\Services\System\BackupService;
use App\Services\Tasks\WeeklySummaryAssignee;
use App\Services\Tasks\WeeklySummaryHealth;
use App\Services\Tasks\WeeklySummaryReport;
use App\Services\Tasks\WeeklySummaryTask;
use App\Support\Notifications\NotificationChannels;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;

/**
 * The weekly summary (decision D-18), sent by WeeklySummary::send() to every
 * active user who holds `task.assign`: the in-app bell with the four figures
 * and a link to the tasks board, plus the full summary by mail when a real
 * mailer is configured and the recipient has not switched it off (mail is on
 * by default for this event). Rendered in the recipient's locale — the mail
 * view sets the direction and alignment of that locale.
 *
 * `health` is present only in a super admin's copy. A listed task links to
 * its page only when it is in `readableTaskIds`, the tasks the recipient's
 * TaskPolicy lets them open; the others are named without a link.
 *
 * The bell is written at once (the `database` channel runs on the sync
 * connection) while the mail leaves through the queue the scheduler drains
 * (D-1). Every value the notice carries is plain data, so it serialises
 * without touching the database.
 */
final class WeeklySummaryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>  $readableTaskIds
     */
    public function __construct(
        public readonly WeeklySummaryReport $report,
        public readonly ?WeeklySummaryHealth $health,
        public readonly array $readableTaskIds,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationEvent::WeeklySummary);
    }

    /**
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title(e(__('weekly_summary.notifications.title')))
            ->body(e(__('weekly_summary.notifications.body', [
                'completed' => $this->report->completed,
                'overdue' => $this->report->overdueTotal,
                'stalled' => $this->report->stalledTotal,
                'handed_out' => $this->report->handedOut,
            ])))
            ->icon(Heroicon::OutlinedChartBar)
            ->iconColor('info')
            ->actions([
                Action::make('open')
                    ->label(__('weekly_summary.notifications.open'))
                    ->url(TasksBoard::getUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('weekly_summary.mail.subject', $this->period()))
            ->markdown('mail.weekly-summary', [
                'recipientName' => $notifiable->name,
                'period' => $this->period(),
                'stats' => [
                    __('weekly_summary.fields.completed') => $this->report->completed,
                    __('weekly_summary.fields.overdue') => $this->report->overdueTotal,
                    __('weekly_summary.fields.stalled') => $this->report->stalledTotal,
                    __('weekly_summary.fields.handed_out') => $this->report->handedOut,
                ],
                'boardUrl' => TasksBoard::getUrl(),
                'assignees' => array_map(static fn (WeeklySummaryAssignee $row): array => [
                    'name' => $row->name ?? __('weekly_summary.fields.unassigned'),
                    'open' => $row->open,
                    'overdue' => $row->overdue,
                    'completed' => $row->completed,
                ], $this->report->assignees),
                'assigneesMore' => $this->report->assigneesMore() > 0
                    ? trans_choice('weekly_summary.mail.assignees_more', $this->report->assigneesMore())
                    : null,
                'lists' => [
                    [
                        'heading' => __('weekly_summary.sections.overdue'),
                        'empty' => __('weekly_summary.empty.overdue'),
                        'lines' => array_map(fn (WeeklySummaryTask $task): array => $this->line(
                            $task,
                            __('weekly_summary.mail.overdue_detail', [
                                'date' => $this->date($task->since),
                                'late' => trans_choice('weekly_summary.mail.days_late', $task->days),
                            ]),
                        ), $this->report->overdue),
                        'more' => $this->more($this->report->overdueMore()),
                    ],
                    [
                        'heading' => __('weekly_summary.sections.stalled'),
                        'empty' => __('weekly_summary.empty.stalled'),
                        'lines' => array_map(fn (WeeklySummaryTask $task): array => $this->line(
                            $task,
                            __('weekly_summary.mail.stalled_detail', [
                                'date' => $this->date($task->since),
                                'quiet' => trans_choice('weekly_summary.mail.days_quiet', $task->days),
                            ]),
                        ), $this->report->stalled),
                        'more' => $this->more($this->report->stalledMore()),
                    ],
                ],
                'health' => $this->health === null ? null : $this->healthLines($this->health),
            ]);
    }

    /**
     * @return array{from: string, to: string}
     */
    private function period(): array
    {
        return [
            'from' => $this->date($this->report->from),
            'to' => $this->date($this->report->to),
        ];
    }

    /** "… and 3 more tasks" under a capped list; null when nothing was left out. */
    private function more(int $count): ?string
    {
        return $count > 0 ? trans_choice('weekly_summary.mail.tasks_more', $count) : null;
    }

    /**
     * @return array{title: string, url: ?string, assignee: string, detail: string}
     */
    private function line(WeeklySummaryTask $task, string $detail): array
    {
        return [
            'title' => $task->title,
            'url' => in_array($task->id, $this->readableTaskIds, true)
                ? TaskResource::getUrl('view', ['record' => $task->id])
                : null,
            'assignee' => $task->assigneeName ?? __('weekly_summary.fields.unassigned'),
            'detail' => $detail,
        ];
    }

    /**
     * @return array{backup: string, backup_problem: ?string, rows: array<string, string>, url: string}
     */
    private function healthLines(WeeklySummaryHealth $health): array
    {
        $latest = $health->latestBackup;
        $timezone = $this->report->to->getTimezone();

        $backup = $latest === null
            ? __('weekly_summary.mail.backup_none')
            : __('weekly_summary.mail.backup_latest', [
                'time' => $this->time($latest->createdAt->setTimezone($timezone)),
                'database' => Number::fileSize($latest->databaseBytes, maxPrecision: 1),
                'files' => Number::fileSize($latest->filesBytes, maxPrecision: 1),
                'total' => Number::fileSize($latest->totalBytes(), maxPrecision: 1),
            ]);

        $problem = null;

        if ($health->backupFailure !== null) {
            $problem = __('backups.pages.last_failure', [
                'time' => $this->time($health->backupFailure->occurredAt->setTimezone($timezone)),
                'reason' => __('backups.reasons.'.$health->backupFailure->reason),
            ]);
        } elseif ($latest !== null && $latest->createdAt->lessThan($this->report->to->subDays(BackupService::STALE_AFTER_DAYS))) {
            $problem = trans_choice('weekly_summary.mail.backup_stale', BackupService::STALE_AFTER_DAYS);
        }

        return [
            'backup' => $backup,
            'backup_problem' => $problem,
            'rows' => [
                __('weekly_summary.fields.failed_jobs') => (string) $health->failedJobs,
                __('weekly_summary.fields.logged_errors') => $health->errorsComplete
                    ? (string) $health->loggedErrors
                    : __('weekly_summary.mail.at_least', ['count' => $health->loggedErrors]),
            ],
            'url' => Backups::getUrl(),
        ];
    }

    private function date(CarbonImmutable $moment): string
    {
        return $moment->format('Y-m-d');
    }

    private function time(CarbonImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i');
    }
}
