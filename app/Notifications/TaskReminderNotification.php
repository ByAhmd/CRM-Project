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
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your task is coming up" — sent once by TaskReminderService when the
 * task's reminder time has passed (decisions A-10, D-10): in-app bell, plus
 * mail when a real mailer is configured. Rendered in the assignee's locale.
 */
final class TaskReminderNotification extends Notification
{
    public function __construct(
        private readonly Task $task,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationEvent::TaskReminder);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon(Heroicon::OutlinedBellAlert)
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

    public function title(): string
    {
        return __('tasks.notifications.reminder_title');
    }

    public function body(): string
    {
        return __('tasks.notifications.reminder_body', [
            'title' => $this->task->title,
            'due' => $this->task->due_at?->format('Y-m-d H:i') ?? __('common.placeholders.empty'),
        ]);
    }

    public function url(): string
    {
        return TaskResource::getUrl('view', ['record' => $this->task]);
    }
}
