<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\Access\PanelAccess;
use App\Support\Filament\PanelUrl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * The Inbox «الوارد» (decision D-27): a reader's own in-app notifications —
 * the same rows the bell shows — and what may be done with them: read the
 * list, open one (it is marked read, then its link is followed when the
 * link points into this site), mark one read or unread, mark all read.
 *
 * A notification belongs to exactly one user (its notifiable). Every query
 * starts from that user and every write checks the row is theirs, so no
 * reader ever sees, opens or marks another user's notice, whatever id the
 * browser sends. Each notice's text and link were written for its recipient
 * when it was sent (the link already checked against what they may open,
 * NotificationLink, PanelUrl::forReader()), and is checked again when the
 * notice is opened: a link into a workspace the reader may no longer enter
 * is not offered.
 */
final class InboxService
{
    /** Where cachedUnreadCount() keeps a reader's count on the request. */
    private const string CACHE_ATTRIBUTE = 'crm.inbox.unread';

    public function __construct(
        private readonly PanelAccess $panels,
    ) {}

    /**
     * The reader's notifications, newest first is the caller's choice.
     *
     * @return Builder<DatabaseNotification>
     */
    public function query(User $reader): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $reader->getMorphClass())
            ->where('notifiable_id', $reader->getKey());
    }

    public function unreadCount(User $reader): int
    {
        return $this->query($reader)->whereNull('read_at')->count();
    }

    /**
     * The unread count for a page's chrome — the Tasks sidebar badge and the
     * phone bar's «الوارد» — counted once per request (Filament evaluates a
     * navigation badge more than once per render) and kept on the request,
     * so the next request always counts afresh. A mark made in the same
     * request forgets it (forgetCachedCount()).
     */
    public function cachedUnreadCount(User $reader, Request $request): int
    {
        $key = self::CACHE_ATTRIBUTE.'.'.$reader->getKey();
        $cached = $request->attributes->get($key);

        if (is_int($cached)) {
            return $cached;
        }

        $count = $this->unreadCount($reader);
        $request->attributes->set($key, $count);

        return $count;
    }

    /** Whether the notification is the reader's own. */
    public function owns(User $reader, DatabaseNotification $notification): bool
    {
        return $notification->getAttribute('notifiable_type') === $reader->getMorphClass()
            && (string) $notification->getAttribute('notifiable_id') === (string) $reader->getKey();
    }

    /**
     * Marks the notification read and answers where to go: its link when it
     * points into this site (same host, http or https, no credentials, no
     * dot segments — PanelUrl::sameSiteAddress()) and, when it leads into a
     * workspace, one the reader may still enter (address()); null otherwise.
     *
     * @throws AuthorizationException when the notification is not the reader's
     */
    public function open(User $reader, DatabaseNotification $notification, Request $request): ?string
    {
        $this->markRead($reader, $notification);

        return $this->address($reader, $notification, $request);
    }

    /**
     * The address a notification leads to: the URL of its first action that
     * has one (the bell's "open"), or null.
     */
    public function link(DatabaseNotification $notification): ?string
    {
        $data = $notification->getAttribute('data');
        $actions = is_array($data) && is_array($data['actions'] ?? null) ? $data['actions'] : [];

        foreach ($actions as $action) {
            $url = is_array($action) ? ($action['url'] ?? null) : null;

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /** Whether the notification leads to a page of this site the reader may still reach. */
    public function hasLink(User $reader, DatabaseNotification $notification, Request $request): bool
    {
        return $this->address($reader, $notification, $request) !== null;
    }

    /**
     * Where the notification leads, rebuilt from its checked parts: an
     * address of this site, and — when it lies in a panel — one the reader
     * may enter now (PanelAccess). A link was built for its reader when the
     * notice was sent, but a workspace can be withdrawn since (a role edited,
     * a `workspace.*` key removed); such a notice has no link any more, so
     * «فتح» never dead-ends on a refusal (D-23).
     */
    private function address(User $reader, DatabaseNotification $notification, Request $request): ?string
    {
        $link = $this->link($notification);
        $address = $link === null ? null : PanelUrl::sameSiteAddress($link, $request);

        if ($address === null) {
            return null;
        }

        $panel = PanelUrl::panelIdForUrl($address, $request);

        return $panel === null || $this->panels->canAccess($reader, $panel) ? $address : null;
    }

    /**
     * @throws AuthorizationException when the notification is not the reader's
     */
    public function markRead(User $reader, DatabaseNotification $notification): void
    {
        $this->ensureOwned($reader, $notification);

        DB::transaction(function () use ($reader, $notification): void {
            $this->query($reader)
                ->whereKey($notification->getKey())
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        });

        $notification->refresh();
        $this->forgetCachedCount($reader);
    }

    /**
     * @throws AuthorizationException when the notification is not the reader's
     */
    public function markUnread(User $reader, DatabaseNotification $notification): void
    {
        $this->ensureOwned($reader, $notification);

        DB::transaction(function () use ($reader, $notification): void {
            $this->query($reader)
                ->whereKey($notification->getKey())
                ->update(['read_at' => null]);
        });

        $notification->refresh();
        $this->forgetCachedCount($reader);
    }

    /**
     * Marks the given notifications read — only those that are the
     * reader's; any other id is ignored. Answers how many changed.
     *
     * @param  iterable<mixed>  $notifications
     */
    public function markManyRead(User $reader, iterable $notifications): int
    {
        $ids = [];

        foreach ($notifications as $notification) {
            if ($notification instanceof DatabaseNotification && $this->owns($reader, $notification)) {
                $ids[] = (string) $notification->getKey();
            }
        }

        if ($ids === []) {
            return 0;
        }

        $changed = DB::transaction(fn (): int => $this->query($reader)
            ->whereKey($ids)
            ->whereNull('read_at')
            ->update(['read_at' => now()]));

        $this->forgetCachedCount($reader);

        return $changed;
    }

    /** Marks every unread notification of the reader read; answers how many changed. */
    public function markAllRead(User $reader): int
    {
        $changed = DB::transaction(fn (): int => $this->query($reader)
            ->whereNull('read_at')
            ->update(['read_at' => now()]));

        $this->forgetCachedCount($reader);

        return $changed;
    }

    private function forgetCachedCount(User $reader): void
    {
        request()->attributes->remove(self::CACHE_ATTRIBUTE.'.'.$reader->getKey());
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureOwned(User $reader, DatabaseNotification $notification): void
    {
        if (! $this->owns($reader, $notification)) {
            throw new AuthorizationException;
        }
    }
}
