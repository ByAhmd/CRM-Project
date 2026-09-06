<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
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
use Illuminate\Support\Traits\Localizable;

/**
 * "A lead of yours has gone quiet" — sent once by LeadStaleService to the
 * owner when an open lead has had no activity for the configured number of
 * days (plan section 3.6, decision D-10; crm.leads.stale_days). Queued (D-1)
 * and rendered in the recipient's locale; the channels follow the owner's
 * preference.
 */
final class LeadStaleNotification extends Notification implements ShouldQueue
{
    use Localizable;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly Lead $lead,
        private readonly int $daysIdle,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationEvent::LeadStale);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return $this->withLocale($this->localeFor($notifiable), fn (): array => FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon(Heroicon::OutlinedClock)
            ->warning()
            ->actions([
                Action::make('open')
                    ->label(__('notifications.common.open'))
                    ->url($this->url())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage());
    }

    public function toMail(User $notifiable): MailMessage
    {
        return $this->withLocale($this->localeFor($notifiable), fn (): MailMessage => (new MailMessage)
            ->subject($this->title())
            ->greeting(__('notifications.common.greeting', ['name' => $notifiable->name]))
            ->line($this->body())
            ->action(__('notifications.common.open'), $this->url()));
    }

    public function title(): string
    {
        return __('notifications.events.lead_stale.title');
    }

    public function body(): string
    {
        return trans_choice('notifications.events.lead_stale.body', $this->daysIdle, [
            'record' => $this->lead->full_name,
            'days' => $this->daysIdle,
        ]);
    }

    public function daysIdle(): int
    {
        return $this->daysIdle;
    }

    public function url(): string
    {
        return LeadResource::getUrl('view', ['record' => $this->lead]);
    }

    private function localeFor(User $notifiable): string
    {
        return $this->locale ?? $notifiable->preferredLocale();
    }
}
