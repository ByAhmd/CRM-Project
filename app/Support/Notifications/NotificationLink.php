<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use Filament\Actions\Action;

/**
 * The bell's "open" action for a link that may be withheld (decision D-23):
 * a reader who may not enter the workspace owning the page gets the notice
 * without a link rather than a button that answers 403
 * (PanelUrl::forReader(), RecordUrl::forReader()). The mails do the same
 * with MailMessage::when(), leaving the button out.
 */
final class NotificationLink
{
    /**
     * The bell's open action, or none when there is no link for this reader.
     *
     * @return list<Action>
     */
    public static function actions(?string $url, string $label): array
    {
        if ($url === null) {
            return [];
        }

        return [
            Action::make('open')
                ->label($label)
                ->url($url)
                ->markAsRead(),
        ];
    }
}
