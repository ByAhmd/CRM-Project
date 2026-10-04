<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationEvent;
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
 * "A colleague mentioned you on a task" — sent by TaskService::comment(),
 * after the comment commits, to every user the comment mentions (decision
 * D-20), who may now open the task as a participant. It replaces the general
 * comment notice for that comment, never comes on top of it. In-app bell and
 * — a mention being a direct call, like an assignment — mail by default
 * when a real mailer is configured (D-10); both switchable in the
 * preferences. Rendered in the recipient's locale.
 *
 * The comment is quoted squished and cut to COMMENT_LIMIT characters, and
 * always as plain text: the bell renders its title and body as sanitised
 * HTML, so both are escaped there, while the mail's lines are escaped by the
 * mail template itself. The bell is written at once (the `database` channel
 * runs on the sync connection) while the mail leaves through the queue the
 * scheduler drains (D-1).
 */
final class TaskMentionNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /** The most characters of the comment the notice quotes. */
    public const int COMMENT_LIMIT = 280;

    public function __construct(
        private readonly Task $task,
        private readonly User $author,
        private readonly TaskUpdate $comment,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationEvent::TaskMention);
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
            ->icon(Heroicon::OutlinedAtSymbol)
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

    private function title(): string
    {
        return __('tasks.notifications.mention_title', [
            'task' => $this->task->title,
            'by' => $this->author->name,
        ]);
    }

    private function body(): string
    {
        return __('tasks.notifications.mention_body', [
            'task' => $this->task->title,
            'by' => $this->author->name,
            'comment' => Str::limit(Str::squish((string) $this->comment->body), self::COMMENT_LIMIT),
        ]);
    }

    private function url(): string
    {
        return TaskResource::getUrl('view', ['record' => $this->task]);
    }
}
