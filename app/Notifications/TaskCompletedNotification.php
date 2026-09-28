<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
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

/**
 * "A task you assigned is done" — sent by TaskService::complete(), after the
 * completion commits, to the task's assigner, or to its creator when no
 * assigner was recorded (decision D-14): in-app bell, plus mail when a real
 * mailer is configured AND the recipient opted in (D-10). Rendered in the
 * recipient's locale.
 *
 * The bell is written at once (the `database` channel runs on the sync
 * connection) while the mail leaves through the queue the scheduler drains
 * (D-1), so a slow or failing mail server can never fail a completion.
 */
final class TaskCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly Task $task,
        private readonly User $completedBy,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationEvent::TaskCompleted);
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
            ->title($this->title())
            ->body($this->body())
            ->icon(Heroicon::OutlinedCheckCircle)
            ->iconColor('success')
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

    private function title(): string
    {
        return __('tasks.notifications.completed_title', ['task' => $this->task->title]);
    }

    private function body(): string
    {
        return __('tasks.notifications.completed_body', [
            'task' => $this->task->title,
            'by' => $this->completedBy->name,
        ]);
    }

    private function url(): string
    {
        return TaskResource::getUrl('view', ['record' => $this->task]);
    }
}
