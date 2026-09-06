<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\OwnedRecord;
use App\Enums\NotificationEvent;
use App\Models\User;
use App\Support\Notifications\NotificationChannels;
use App\Support\RecordLabel;
use App\Support\RecordUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A record was assigned to you" — in-app bell, plus mail when a real mailer
 * is configured (D-10). Not queued: one message per human action.
 */
final class RecordAssignedNotification extends Notification
{
    public function __construct(
        private readonly Model&OwnedRecord $record,
        private readonly User $assignedBy,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationEvent::RecordAssigned);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon('heroicon-o-user-plus')
            ->actions([
                Action::make('open')
                    ->label(__('assignment.notifications.open'))
                    ->url($this->url())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting(__('assignment.notifications.greeting', ['name' => $notifiable->name]))
            ->line($this->body())
            ->action(__('assignment.notifications.open'), $this->url());
    }

    private function title(): string
    {
        return __('assignment.notifications.title', ['entity' => $this->entityLabel()]);
    }

    private function body(): string
    {
        return __('assignment.notifications.body', [
            'entity' => $this->entityLabel(),
            'record' => $this->recordLabel(),
            'by' => $this->assignedBy->name,
        ]);
    }

    private function entityLabel(): string
    {
        return __('assignment.entities.'.$this->record::permissionGroup());
    }

    private function recordLabel(): string
    {
        return RecordLabel::of($this->record);
    }

    private function url(): string
    {
        return RecordUrl::view($this->record);
    }
}
