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
 * "Your lead was converted" — sent by LeadConversionWorkflow to the owner
 * once the conversion has committed and someone else performed it (plan
 * section 3.6, decisions D-7, D-10). Names what the conversion produced.
 * Queued (D-1) and rendered in the recipient's locale; the channels follow
 * the owner's preference.
 */
final class LeadConvertedNotification extends Notification implements ShouldQueue
{
    use Localizable;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly Lead $lead,
        private readonly ?string $accountLabel,
        private readonly string $contactLabel,
        private readonly ?string $dealLabel,
        private readonly User $actor,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationEvent::LeadConverted);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return $this->withLocale($this->localeFor($notifiable), fn (): array => FilamentNotification::make()
            ->title($this->title())
            ->body(implode(' ', [$this->body(), ...$this->details()]))
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->success()
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
        return $this->withLocale($this->localeFor($notifiable), function () use ($notifiable): MailMessage {
            $mail = (new MailMessage)
                ->subject($this->title())
                ->greeting(__('notifications.common.greeting', ['name' => $notifiable->name]))
                ->line($this->body());

            foreach ($this->details() as $detail) {
                $mail->line($detail);
            }

            return $mail->action(__('notifications.common.open'), $this->url());
        });
    }

    public function title(): string
    {
        return __('notifications.events.lead_converted.title');
    }

    public function body(): string
    {
        return __('notifications.events.lead_converted.body', [
            'record' => $this->lead->full_name,
            'by' => $this->actor->name,
        ]);
    }

    /**
     * One line per record the conversion produced, in the recipient's locale.
     *
     * @return list<string>
     */
    public function details(): array
    {
        $details = [];

        if ($this->accountLabel !== null && $this->accountLabel !== '') {
            $details[] = __('notifications.events.lead_converted.account', ['account' => $this->accountLabel]);
        }

        $details[] = __('notifications.events.lead_converted.contact', ['contact' => $this->contactLabel]);

        if ($this->dealLabel !== null && $this->dealLabel !== '') {
            $details[] = __('notifications.events.lead_converted.deal', ['deal' => $this->dealLabel]);
        }

        return $details;
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
