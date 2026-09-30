<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Filament\Pages\System\Backups;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Services\System\BackupFailure;
use App\Support\Notifications\NotificationChannels;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "The backup failed" — sent by BackupService to every active super admin
 * when a run wrote no complete set (decision D-16): the in-app bell, plus
 * mail when a real mailer is configured and the recipient has not switched
 * it off (mail is on by default for this event). Rendered in the recipient's
 * locale; the reason is translated, the technical detail stays on the
 * Backups page and in the log.
 *
 * The bell is written at once (the `database` channel runs on the sync
 * connection) while the mail leaves through the queue the scheduler drains
 * (D-1).
 */
final class BackupFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly BackupFailure $failure,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationEvent::BackupFailed);
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
            ->title(__('backups.notifications.failed_title'))
            ->body($this->body())
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->iconColor('danger')
            ->actions([
                Action::make('open')
                    ->label(__('backups.notifications.open'))
                    ->url(Backups::getUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject(__('backups.notifications.failed_title'))
            ->greeting(__('backups.notifications.greeting', ['name' => $notifiable->name]))
            ->line($this->body())
            ->line(__('backups.notifications.failed_mail_hint'))
            ->action(__('backups.notifications.open'), Backups::getUrl());
    }

    private function body(): string
    {
        return __('backups.notifications.failed_body', [
            'time' => $this->failure->occurredAt->setTimezone(app(SettingsRepository::class)->timezone())->format('Y-m-d H:i'),
            'reason' => __('backups.reasons.'.$this->failure->reason),
        ]);
    }
}
