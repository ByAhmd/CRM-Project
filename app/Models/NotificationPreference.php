<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationEvent;
use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's channel choice for one notification event (plan section 3.6,
 * decision D-10).
 *
 * Written by NotificationPreferenceService only; a user without a row for an
 * event has the enum defaults (bell on, mail off). The service audits every
 * change on the user, so the row itself is not audited.
 *
 * @property NotificationEvent $event
 * @property bool $database
 * @property bool $mail
 */
#[Fillable(['user_id', 'event', 'database', 'mail'])]
final class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => NotificationEvent::class,
            'database' => 'boolean',
            'mail' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
