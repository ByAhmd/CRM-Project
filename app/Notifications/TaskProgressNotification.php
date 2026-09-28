<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Notifications\NotificationChannels;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * "Work started on a task you assigned" / "progress was posted on it" — sent
 * by TaskService::start() and ::postUpdate(), after the entry commits, to
 * the task's assigner, or to its creator when no assigner was recorded
 * (decision D-14, amended 2026-09-28): in-app bell by default, plus mail
 * when a real mailer is configured AND the recipient opted in (D-10).
 * Rendered in the recipient's locale. Completion keeps its own notice
 * (TaskCompletedNotification).
 *
 * The author's text is quoted squished and cut to NOTE_LIMIT characters, and
 * always as plain text: the bell renders its title and body as sanitised
 * HTML, so both are escaped there, while the mail's lines are escaped by the
 * mail template itself. The bell is written at once (the `database` channel
 * runs on the sync connection) while the mail leaves through the queue the
 * scheduler drains (D-1).
 */
final class TaskProgressNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /** The most characters of the author's text the notice quotes. */
    public const int NOTE_LIMIT = 280;

    public function __construct(
        private readonly Task $task,
        private readonly User $reportedBy,
        private readonly TaskUpdate $update,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationEvent::TaskProgress);
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
            ->title(e($this->title()))
            ->body(e($this->body()))
            ->icon($this->isStart() ? Heroicon::OutlinedPlayCircle : Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->iconColor('info')
            ->actions([
                Action::make('open')
                    ->label(__('tasks.notifications.open'))
                    ->url($this->url())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting(__('tasks.notifications.greeting', ['name' => $notifiable->name]))
            ->line($this->body())
            ->action(__('tasks.notifications.open'), $this->url());
    }

    private function isStart(): bool
    {
        return $this->update->status === TaskStatus::InProgress;
    }

    private function title(): string
    {
        return __($this->isStart() ? 'tasks.notifications.started_title' : 'tasks.notifications.progress_title', [
            'task' => $this->task->title,
        ]);
    }

    private function body(): string
    {
        $note = $this->note();

        if ($this->isStart()) {
            return $note === null
                ? __('tasks.notifications.started_body', ['task' => $this->task->title, 'by' => $this->reportedBy->name])
                : __('tasks.notifications.started_body_with_note', ['task' => $this->task->title, 'by' => $this->reportedBy->name, 'note' => $note]);
        }

        return __('tasks.notifications.progress_body', [
            'task' => $this->task->title,
            'by' => $this->reportedBy->name,
            'note' => $note ?? '',
        ]);
    }

    /** The author's text on one line, cut to NOTE_LIMIT characters; null when there is none. */
    private function note(): ?string
    {
        $text = Str::squish((string) $this->update->body);

        return $text === '' ? null : Str::limit($text, self::NOTE_LIMIT);
    }

    private function url(): string
    {
        return TaskResource::getUrl('view', ['record' => $this->task]);
    }
}
