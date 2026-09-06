<?php

declare(strict_types=1);

namespace App\Notifications;

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
 * "Someone moved your deal to another stage" — sent by DealStageWorkflow
 * to the owner after an open-stage transition or a reopen made by someone
 * else (plan section 3.6, decision D-10). Queued (D-1) and rendered in the
 * recipient's locale; the channels follow the owner's preference.
 */
final class DealStageChangedNotification extends Notification implements ShouldQueue
{
    use Localizable;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly Deal $deal,
        private readonly string $fromStage,
        private readonly string $toStage,
        private readonly User $actor,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationEvent::DealStageChanged);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return $this->withLocale($this->localeFor($notifiable), fn (): array => FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon(Heroicon::OutlinedArrowsRightLeft)
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
        return __('notifications.events.deal_stage_changed.title');
    }

    public function body(): string
    {
        return __('notifications.events.deal_stage_changed.body', [
            'record' => $this->deal->title,
            'by' => $this->actor->name,
            'from' => $this->fromStage,
            'to' => $this->toStage,
        ]);
    }

    public function url(): string
    {
        return DealResource::getUrl('view', ['record' => $this->deal]);
    }

    private function localeFor(User $notifiable): string
    {
        return $this->locale ?? $notifiable->preferredLocale();
    }
}
