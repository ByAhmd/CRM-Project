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
 * recipient's locale. A task completed with unticked checklist items says
 * how many were left (D-25).
 *
 * The bell is written at once (the `database` channel runs on the sync
 * connection) while the mail leaves through the queue the scheduler drains
 * (D-1), so a slow or failing mail server can never fail a completion.
 */
final class TaskCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * The checklist counts are captured when the task is completed (D-25), so
     * the notice says what was left open then, whatever happens to the
     * checklist before the queued mail leaves.
     */
    public function __construct(
        private readonly Task $task,
        private readonly User $completedBy,
        private readonly int $checklistOpen = 0,
        private readonly int $checklistTotal = 0,
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

    private function title(): string
    {
        return __('tasks.notifications.completed_title', ['task' => $this->task->title]);
    }

    /**
     * Who completed the task — and, when its checklist was left unfinished,
     * how many items of how many were not done (D-25).
     */
    private function body(): string
    {
        $body = __('tasks.notifications.completed_body', [
            'task' => $this->task->title,
            'by' => $this->completedBy->name,
        ]);

        if ($this->checklistOpen <= 0 || $this->checklistTotal <= 0) {
            return $body;
        }

        // The noun follows the checklist's size («من 5 بنود»), so the plural
        // form is chosen by the total.
        return $body.' '.trans_choice('tasks.notifications.completed_checklist_open', $this->checklistTotal, [
            'count' => $this->checklistOpen,
            'total' => $this->checklistTotal,
        ]);
    }

    private function url(): string
    {
        return TaskResource::getUrl('view', ['record' => $this->task]);
    }

    /** The task's page, unless the recipient may not enter the Tasks workspace (D-23). */
    private function linkFor(User $notifiable): ?string
    {
        return PanelUrl::forReader($notifiable, TaskResource::class, fn (): string => $this->url());
    }
}
