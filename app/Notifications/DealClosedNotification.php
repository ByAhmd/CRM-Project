<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\DealStatus;
use App\Enums\NotificationEvent;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
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
 * "A deal was won / lost" — sent by DealStageWorkflow to the owner and the
 * owner's team manager (never the actor) after a Won or Lost transition
 * (plan section 3.6, decision D-10). Queued (D-1) and rendered in each
 * recipient's locale; the channels follow the recipient's preference.
 */
final class DealClosedNotification extends Notification implements ShouldQueue
{
    use Localizable;
    use Queueable;
    use SerializesModels;

    /**
     * @param  DealStatus  $outcome  Won or Lost.
     */
    public function __construct(
        private readonly Deal $deal,
        private readonly DealStatus $outcome,
        private readonly string $reason,
        private readonly User $actor,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationEvent::DealClosed);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return $this->withLocale($this->localeFor($notifiable), function (): array {
            $notification = FilamentNotification::make()
                ->title($this->title())
                ->body($this->body())
                ->icon($this->isWon() ? Heroicon::OutlinedTrophy : Heroicon::OutlinedXCircle)
                ->actions([
                    Action::make('open')
                        ->label(__('notifications.common.open'))
                        ->url($this->url())
                        ->markAsRead(),
                ]);

            $this->isWon() ? $notification->success() : $notification->warning();

            return $notification->getDatabaseMessage();
        });
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
        return __($this->isWon() ? 'notifications.events.deal_closed.title_won' : 'notifications.events.deal_closed.title_lost');
    }

    public function body(): string
    {
        return __($this->isWon() ? 'notifications.events.deal_closed.body_won' : 'notifications.events.deal_closed.body_lost', [
            'record' => $this->deal->title,
            'by' => $this->actor->name,
            'reason' => $this->reason,
        ]);
    }

    public function url(): string
    {
        return DealResource::getUrl('view', ['record' => $this->deal]);
    }

    public function isWon(): bool
    {
        return $this->outcome === DealStatus::Won;
    }

    private function localeFor(User $notifiable): string
    {
        return $this->locale ?? $notifiable->preferredLocale();
    }
}
