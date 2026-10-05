<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Note;
use App\Models\User;
use App\Support\Notifications\NotificationChannels;
use App\Support\Notifications\NotificationLink;
use App\Support\RecordUrl;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Traits\Localizable;

/**
 * "You were mentioned in a note" — sent by NoteService to every user the
 * author explicitly mentioned, except the author (plan section 3.6,
 * decisions A-10, D-10). Mentions are not stored: this notification is the
 * record of one. Opens the record the note was written on. Queued (D-1) and
 * rendered in the recipient's locale; the channels follow the recipient's
 * preference.
 */
final class NoteMentionNotification extends Notification implements ShouldQueue
{
    use Localizable;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly Note $note,
        private readonly User $actor,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return NotificationChannels::for($notifiable, NotificationEvent::NoteMention);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return $this->withLocale($this->localeFor($notifiable), fn (): array => FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->actions(NotificationLink::actions($this->linkFor($notifiable), __('notifications.common.open')))
            ->getDatabaseMessage());
    }

    public function toMail(User $notifiable): MailMessage
    {
        return $this->withLocale($this->localeFor($notifiable), fn (): MailMessage => (new MailMessage)
            ->subject($this->title())
            ->greeting(__('notifications.common.greeting', ['name' => $notifiable->name]))
            ->line($this->body())
            ->when($this->linkFor($notifiable), static fn (MailMessage $mail, string $url): MailMessage => $mail->action(__('notifications.common.open'), $url)));
    }

    public function title(): string
    {
        return __('notifications.events.note_mention.title');
    }

    public function body(): string
    {
        return __('notifications.events.note_mention.body', [
            'author' => $this->actor->name,
            'record' => $this->note->subjectLabel(),
            'excerpt' => $this->note->excerpt(120),
        ]);
    }

    /** The view page of the record the note was written on; the workspace screen when it is gone. */
    public function url(): string
    {
        $subject = $this->note->subjectRecord();

        return $subject === null ? RecordUrl::home() : RecordUrl::view($subject);
    }

    private function localeFor(User $notifiable): string
    {
        return $this->locale ?? $notifiable->preferredLocale();
    }

    /**
     * The noted record's page, unless the recipient may not enter its
     * workspace (D-23); the workspace screen when the record is gone.
     */
    private function linkFor(User $notifiable): ?string
    {
        $subject = $this->note->subjectRecord();

        return $subject === null ? RecordUrl::home() : RecordUrl::forReader($notifiable, $subject);
    }
}
