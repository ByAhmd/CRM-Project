<?php

declare(strict_types=1);

namespace App\Filament\Pages\System;

use App\Enums\NavigationGroup;
use App\Enums\Permission;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Services\System\BackupService;
use App\Services\System\BackupSet;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Number;

/**
 * System → Backups (decision D-16): every kept backup set with its date and
 * sizes, a download of each of its two files through the authorised route
 * `backups.download`, and *Back up now*, which queues a run for the next
 * scheduler drain. Super admins only (`roles.manage`), like the Roles screen:
 * a backup is the whole database.
 *
 * The page presents; listing, queuing and downloads are BackupService's.
 */
final class Backups extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 80;

    protected string $view = 'filament.pages.system.backups';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::System;
    }

    public static function getNavigationLabel(): string
    {
        return __('backups.navigation');
    }

    public function getTitle(): string
    {
        return __('backups.pages.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can(Permission::RolesManage->value);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->records())
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('backups.fields.created_at'))
                    ->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('database_size')
                    ->label(__('backups.fields.database'))
                    ->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('files_size')
                    ->label(__('backups.fields.files'))
                    ->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('total_size')
                    ->label(__('backups.fields.total'))
                    ->weight('medium')
                    ->extraAttributes(['dir' => 'ltr']),
            ])
            ->recordActions([
                $this->downloadAction('downloadDatabase', BackupSet::DATABASE, __('backups.actions.download_database'), Heroicon::OutlinedCircleStack),
                $this->downloadAction('downloadFiles', BackupSet::FILES, __('backups.actions.download_files'), Heroicon::OutlinedFolder),
            ])
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedArchiveBox)
            ->emptyStateHeading(__('backups.empty.heading'))
            ->emptyStateDescription(__('backups.empty.description'));
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backUpNow')
                ->label(__('backups.actions.back_up_now'))
                ->icon(Heroicon::OutlinedCloudArrowUp)
                ->authorize(fn (): bool => self::canAccess())
                ->requiresConfirmation()
                ->modalHeading(__('backups.confirmations.back_up_now.heading'))
                ->modalDescription(__('backups.confirmations.back_up_now.description'))
                ->modalSubmitActionLabel(__('backups.actions.back_up_now'))
                ->action(function (): void {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(BackupService::class)->queue($user);

                    Notification::make()
                        ->success()
                        ->title(__('backups.notifications.queued_title'))
                        ->body(__('backups.notifications.queued_body'))
                        ->send();
                }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $backups = app(BackupService::class);
        $failure = $backups->lastFailure();
        $timezone = app(SettingsRepository::class)->timezone();

        return [
            'failure' => $failure,
            'failureTime' => $failure?->occurredAt->setTimezone($timezone)->format('Y-m-d H:i'),
            'keep' => max(1, (int) config('crm.backup.keep')),
        ];
    }

    /**
     * A plain link to the authorised download route. It opens in a new tab,
     * like attachment downloads: the panel runs in SPA mode with prefetching,
     * and a same-tab link would be Livewire-navigated — prefetched on hover
     * (streaming and auditing a whole dump nobody asked for) and swapped in as
     * a page on click instead of being saved as a file.
     */
    private function downloadAction(string $name, string $part, string $label, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color('gray')
            ->authorize(fn (): bool => self::canAccess())
            ->url(fn (array $record): string => route('backups.download', ['backup' => $record['id'], 'file' => $part]), shouldOpenInNewTab: true);
    }

    /**
     * The table rows, keyed by set id, newest first.
     *
     * @return array<string, array<string, string>>
     */
    private function records(): array
    {
        $timezone = app(SettingsRepository::class)->timezone();
        $rows = [];

        foreach (app(BackupService::class)->sets() as $set) {
            $rows[$set->id] = [
                'id' => $set->id,
                'created_at' => $set->createdAt->setTimezone($timezone)->format('Y-m-d H:i'),
                'database_size' => Number::fileSize($set->databaseBytes, maxPrecision: 1),
                'files_size' => Number::fileSize($set->filesBytes, maxPrecision: 1),
                'total_size' => Number::fileSize($set->totalBytes(), maxPrecision: 1),
            ];
        }

        return $rows;
    }
}
