<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Support\Filament\PanelUrl;
use App\Support\Notifications\NotificationChannels;
use App\Support\Notifications\NotificationLink;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your task is overdue" — sent once by TaskReminderService when an open
 * task passes its due date (decisions A-10, D-10): in-app bell, plus mail
 * when a real mailer is configured. Every assignee of a shared task receives
 * their own (D-28), rendered in that assignee's locale.
 */
final class TaskOverdueNotification extends Notification
{
    public function __construct(
        private readonly Task $task,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationEvent::TaskOverdue);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->danger()
            ->actions(NotificationLink::actions($this->linkFor($notifiable), __('tasks.notifications.open')))
            ->getDatabaseMessage();
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting(__('tasks.notifications.greeting', ['name' => $notifiable->name]))
            ->line($this->body())
            ->when($this->linkFor($notifiable), static fn (MailMessage $mail, string $url): MailMessage => $mail->action(__('tasks.notifications.open'), $url));
    }

    public function title(): string
    {
        return __('tasks.notifications.overdue_title');
    }

    public function body(): string
    {
        return __('tasks.notifications.overdue_body', [
            'title' => $this->task->title,
            'due' => $this->task->due_at?->format('Y-m-d H:i') ?? __('common.placeholders.empty'),
        ]);
    }

    public function url(): string
    {
        return TaskResource::getUrl('view', ['record' => $this->task]);
    }

    /** The task's page, unless the recipient may not enter the Tasks workspace (D-23). */
    private function linkFor(User $notifiable): ?string
    {
        return PanelUrl::forReader($notifiable, TaskResource::class, fn (): string => $this->url());
    }
}
