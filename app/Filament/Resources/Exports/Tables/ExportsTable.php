<?php

declare(strict_types=1);

namespace App\Filament\Resources\Exports\Tables;

use App\Filament\Resources\Exports\ExportResource;
use App\Filament\Support\LtrText;
use App\Models\Export;
use App\Services\Settings\SettingsRepository;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The export history listing (module 18, decision D-13): one row per run,
 * newest first, with a download per format while the files exist.
 *
 * Downloads stream through Filament's own downloaders from the private
 * disk, authorised by ExportPolicy::view — the owner always, `exports.view`
 * holders for every run. Nothing under storage is ever web-served.
 */
final class ExportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                LtrText::column(
                    TextColumn::make('file_name')
                        ->label(__('exports.fields.file_name'))
                        ->searchable()
                        ->placeholder(__('common.placeholders.empty'))
                        ->weight('semibold'),
                ),

                TextColumn::make('exporter')
                    ->label(__('exports.fields.entity'))
                    ->formatStateUsing(fn (string $state): string => ExportResource::entityLabel($state))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('user.name')
                    ->label(__('exports.fields.user'))
                    ->placeholder(__('common.placeholders.empty'))
                    ->sortable(),

                TextColumn::make('total_rows')
                    ->label(__('exports.fields.total_rows'))
                    ->numeric()
                    ->sortable(),

                TextColumn::make('successful_rows')
                    ->label(__('exports.fields.successful_rows'))
                    ->numeric()
                    ->color('success'),

                TextColumn::make('failed_rows_count')
                    ->label(__('exports.fields.failed_rows'))
                    ->state(fn (Export $record): int => $record->getFailedRowsCount())
                    ->numeric()
                    ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),

                TextColumn::make('status')
                    ->label(__('exports.fields.status'))
                    ->state(fn (Export $record): string => ExportResource::status($record))
                    ->formatStateUsing(fn (string $state): string => __('exports.statuses.'.$state))
                    ->badge()
                    ->color(fn (string $state): string => ExportResource::statusColor($state)),

                TextColumn::make('created_at')
                    ->label(__('exports.fields.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('completed_at')
                    ->label(__('exports.fields.completed_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder(__('common.placeholders.empty'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('exporter')
                    ->label(__('exports.filters.entity'))
                    ->options(fn (): array => ExportResource::entityOptions())
                    ->multiple(),

                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label(__('exports.filters.from'))->native(false),
                        DatePicker::make('until')->label(__('exports.filters.until'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['from'] ?? null), fn (Builder $query): Builder => $query->where('created_at', '>=', app(SettingsRepository::class)->startOfOrganisationDay((string) $data['from'])))
                        ->when(filled($data['until'] ?? null), fn (Builder $query): Builder => $query->where('created_at', '<=', app(SettingsRepository::class)->endOfOrganisationDay((string) $data['until']))))
                    ->indicateUsing(fn (array $data): array => array_filter([
                        filled($data['from'] ?? null) ? __('exports.filters.from').': '.$data['from'] : null,
                        filled($data['until'] ?? null) ? __('exports.filters.until').': '.$data['until'] : null,
                    ])),
            ])
            // One three-dot menu per row instead of a wall of links; every
            // action keeps its own authorisation, and the menu hides itself
            // when the policy refuses every action in it.
            ->recordActions([
                ActionGroup::make([
                    self::download(ExportFormat::Csv),
                    self::download(ExportFormat::Xlsx),
                ])
                    ->label(__('app.actions.row_actions'))
                    ->tooltip(__('app.actions.row_actions')),
            ])
            ->emptyStateHeading(__('exports.empty.heading'))
            ->emptyStateDescription(__('exports.empty.description'));
    }

    /**
     * Streams the run's file in one format, while the files are still on
     * the private disk and only to a user ExportPolicy lets view the run.
     */
    public static function download(ExportFormat $format): Action
    {
        return Action::make('download_'.$format->value)
            ->label(__('exports.actions.download_'.$format->value))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (Export $record): bool => $record->hasFile())
            ->authorize(fn (Export $record): bool => auth()->user()?->can('view', $record) ?? false)
            ->action(fn (Export $record): StreamedResponse => $format->getDownloader()($record));
    }
}
