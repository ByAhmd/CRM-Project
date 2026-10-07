<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\WorkspacePanel;
use App\Filament\Concerns\BuildsPageUrlsInOwningPanel;
use App\Models\User;
use App\Services\Notifications\InboxService;
use App\Services\Settings\SettingsRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The Inbox «الوارد» (decision D-27): a full page of the reader's in-app
 * notifications — the bell's rows, with room to work through them. Unread
 * first, then newest; tabs All / Unread; a row opens its notice (marked read,
 * then its link followed when it points into this site), the row menu marks
 * it read or unread, and "Mark all read" clears the lot. Only the reader's own
 * notifications are ever listed or touched: InboxService starts every query
 * from the reader and checks every write.
 *
 * One page per panel, all built from this base: the Inbox opens INSIDE the
 * workspace the reader is in, with that workspace's sidebar (the first build
 * kept a single page in the home panel, which has no sidebar, so opening it
 * from Tasks lost the way back — the owner's screenshot, 2026-10-07). Each
 * workspace lists its Inbox at the top of its sidebar with the unread count;
 * the home panel's copy serves the workspace screen. urlFor() picks the copy
 * for a panel, so the user menu and the Tasks phone bar stay in place.
 *
 * The page presents only; the reading and marking rules are InboxService's.
 */
abstract class InboxPage extends Page implements HasTable
{
    use BuildsPageUrlsInOwningPanel;
    use HasTabs;
    use InteractsWithTable;

    public const string TAB_ALL = 'all';

    public const string TAB_UNREAD = 'unread';

    /** The bell refreshes on this Filament event; sent after every change here so both agree at once. */
    private const string BELL_REFRESH_EVENT = 'databaseNotificationsSent';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $slug = 'inbox';

    /** Right under the workspace's home, above every navigation group. */
    protected static ?int $navigationSort = -1;

    /**
     * The Inbox page that belongs to a panel: its own copy for each workspace,
     * the home panel's otherwise.
     *
     * @return class-string<InboxPage>
     */
    public static function forPanel(?string $panelId): string
    {
        return match ($panelId) {
            WorkspacePanel::Sales->id() => SalesInbox::class,
            WorkspacePanel::Tasks->id() => TasksInbox::class,
            WorkspacePanel::Reports->id() => ReportsInbox::class,
            WorkspacePanel::Admin->id() => AdminInbox::class,
            default => Inbox::class,
        };
    }

    /** The Inbox of the panel the reader is in. */
    public static function urlForCurrentPanel(): string
    {
        return static::forPanel(filament()->getId())::getUrl();
    }

    public static function getNavigationLabel(): string
    {
        return __('inbox.navigation.label');
    }

    /** The unread count beside «الوارد» in the sidebar; nothing at zero (one count per request, InboxService). */
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        $unread = $user instanceof User ? app(InboxService::class)->cachedUnreadCount($user, request()) : 0;

        return $unread > 0 ? (string) $unread : null;
    }

    public function getTitle(): string
    {
        return __('inbox.pages.title');
    }

    public function getSubheading(): string
    {
        return __('inbox.pages.subheading');
    }

    /** Every signed-in user reads their own notifications; there is nothing else on the page. */
    public static function canAccess(): bool
    {
        return auth()->user() instanceof User;
    }

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            self::TAB_ALL => Tab::make(__('inbox.filters.all')),
            self::TAB_UNREAD => Tab::make(__('inbox.filters.unread'))
                ->badge(fn (): ?int => ($count = $this->unreadCount()) > 0 ? $count : null)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->whereNull('read_at')),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return self::TAB_ALL;
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->modifyQueryWithActiveTab($this->inbox()->query($this->reader())))
            ->columns([
                TextColumn::make('state')
                    ->label(__('inbox.fields.state'))
                    ->state(static fn (DatabaseNotification $record): string => $record->read_at === null ? self::TAB_UNREAD : 'read')
                    ->formatStateUsing(static fn (string $state): string => $state === self::TAB_UNREAD ? __('inbox.fields.unread') : __('inbox.fields.read'))
                    ->icon(static fn (string $state): Heroicon => $state === self::TAB_UNREAD ? Heroicon::Envelope : Heroicon::OutlinedEnvelopeOpen)
                    ->color(static fn (string $state): string => $state === self::TAB_UNREAD ? 'primary' : 'gray')
                    ->badge(),

                TextColumn::make('title')
                    ->label(__('inbox.fields.notification'))
                    ->state(static fn (DatabaseNotification $record): HtmlString => self::richText($record, 'title'))
                    ->description(static fn (DatabaseNotification $record): ?HtmlString => self::hasText($record, 'body') ? self::richText($record, 'body') : null)
                    ->weight(static fn (DatabaseNotification $record): FontWeight => $record->read_at === null ? FontWeight::Bold : FontWeight::Medium)
                    ->wrap(),

                TextColumn::make('created_at')
                    ->label(__('inbox.fields.received'))
                    ->since()
                    ->dateTimeTooltip('Y-m-d H:i', timezone: app(SettingsRepository::class)->timezone())
                    ->visibleFrom('sm'),
            ])
            // Unread first, then newest: what still asks for attention leads.
            ->defaultSort(static fn (Builder $query): Builder => $query
                ->orderByRaw('`read_at` IS NULL DESC')
                ->orderByDesc('created_at')
                ->orderByDesc('id'))
            ->recordUrl(null)
            ->recordAction(fn (DatabaseNotification $record): ?string => $this->inbox()->hasLink($this->reader(), $record, request()) ? 'open' : ($record->read_at === null ? 'markRead' : null))
            ->recordActions([
                ActionGroup::make([
                    $this->openAction(),
                    $this->markReadAction(),
                    $this->markUnreadAction(),
                ])->label(__('app.actions.row_actions')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markSelectedRead')
                        ->label(__('inbox.actions.mark_selected_read'))
                        ->icon(Heroicon::OutlinedEnvelopeOpen)
                        ->authorizeIndividualRecords(fn (Model $record): bool => $record instanceof DatabaseNotification && $this->inbox()->owns($this->reader(), $record))
                        ->action(function (Collection $records): void {
                            $changed = $this->inbox()->markManyRead($this->reader(), $records);

                            $this->dispatch(self::BELL_REFRESH_EVENT);

                            Notification::make()
                                ->title(trans_choice('inbox.notifications.marked_read_many', $changed, ['count' => $changed]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->poll('30s')
            ->emptyStateIcon(Heroicon::OutlinedInbox)
            ->emptyStateHeading(fn (): string => $this->activeTab === self::TAB_UNREAD ? __('inbox.empty.unread_heading') : __('inbox.empty.heading'))
            ->emptyStateDescription(fn (): string => $this->activeTab === self::TAB_UNREAD ? __('inbox.empty.unread_description') : __('inbox.empty.description'))
            ->emptyStateActions([
                Action::make('preferences')
                    ->label(__('inbox.actions.preferences'))
                    ->icon(Heroicon::OutlinedBellAlert)
                    ->color('gray')
                    ->url(NotificationPreferences::getUrl())
                    ->authorize(static fn (): bool => NotificationPreferences::canAccess()),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('markAllRead')
                ->label(__('inbox.actions.mark_all_read'))
                ->icon(Heroicon::OutlinedCheck)
                ->color('gray')
                ->visible(fn (): bool => $this->unreadCount() > 0)
                ->authorize(static fn (): bool => self::canAccess())
                ->action(function (): void {
                    $changed = $this->inbox()->markAllRead($this->reader());

                    $this->dispatch(self::BELL_REFRESH_EVENT);

                    Notification::make()
                        ->title(trans_choice('inbox.notifications.marked_read_many', $changed, ['count' => $changed]))
                        ->success()
                        ->send();
                }),
        ];
    }

    /** Opens the notice: marks it read, then follows its link into this site. */
    public function openAction(): Action
    {
        return Action::make('open')
            ->label(__('inbox.actions.open'))
            ->icon(Heroicon::OutlinedEye)
            ->visible(fn (?Model $record): bool => $record instanceof DatabaseNotification && $this->inbox()->hasLink($this->reader(), $record, request()))
            ->authorize(fn (?Model $record): bool => $record instanceof DatabaseNotification && $this->inbox()->owns($this->reader(), $record))
            ->action(function (DatabaseNotification $record): void {
                $url = $this->inbox()->open($this->reader(), $record, request());

                $this->dispatch(self::BELL_REFRESH_EVENT);

                if ($url !== null) {
                    $this->redirect($url);
                }
            });
    }

    public function markReadAction(): Action
    {
        return Action::make('markRead')
            ->label(__('inbox.actions.mark_read'))
            ->icon(Heroicon::OutlinedEnvelopeOpen)
            ->visible(static fn (?Model $record): bool => $record instanceof DatabaseNotification && $record->read_at === null)
            ->authorize(fn (?Model $record): bool => $record instanceof DatabaseNotification && $this->inbox()->owns($this->reader(), $record))
            ->action(function (DatabaseNotification $record): void {
                $this->inbox()->markRead($this->reader(), $record);

                $this->dispatch(self::BELL_REFRESH_EVENT);
            });
    }

    public function markUnreadAction(): Action
    {
        return Action::make('markUnread')
            ->label(__('inbox.actions.mark_unread'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(static fn (?Model $record): bool => $record instanceof DatabaseNotification && $record->read_at !== null)
            ->authorize(fn (?Model $record): bool => $record instanceof DatabaseNotification && $this->inbox()->owns($this->reader(), $record))
            ->action(function (DatabaseNotification $record): void {
                $this->inbox()->markUnread($this->reader(), $record);

                $this->dispatch(self::BELL_REFRESH_EVENT);
            });
    }

    private function unreadCount(): int
    {
        return $this->inbox()->unreadCount($this->reader());
    }

    /**
     * A notice's title or body as the bell shows it: the stored text was
     * escaped when the notice was written, and is sanitised again here, so
     * entities read as their characters and no markup ever runs.
     */
    private static function richText(DatabaseNotification $record, string $key): HtmlString
    {
        $data = $record->getAttribute('data');
        $text = is_array($data) && is_string($data[$key] ?? null) ? $data[$key] : '';

        return new HtmlString(Str::sanitizeHtml($text));
    }

    private static function hasText(DatabaseNotification $record, string $key): bool
    {
        $data = $record->getAttribute('data');

        return is_array($data) && is_string($data[$key] ?? null) && trim($data[$key]) !== '';
    }

    private function inbox(): InboxService
    {
        return app(InboxService::class);
    }

    private function reader(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
