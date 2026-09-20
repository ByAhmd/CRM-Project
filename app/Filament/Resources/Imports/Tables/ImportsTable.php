<?php

declare(strict_types=1);

namespace App\Filament\Resources\Imports\Tables;

use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Support\LtrText;
use App\Models\Import;
use App\Services\Settings\SettingsRepository;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The import history listing (module 18): one row per run, newest first,
 * with the entity, the launcher, the counts and a derived status.
 */
final class ImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                LtrText::column(
                    TextColumn::make('file_name')
                        ->label(__('imports.fields.file_name'))
                        ->searchable()
                        ->weight('semibold'),
                ),

                TextColumn::make('importer')
                    ->label(__('imports.fields.entity'))
                    ->formatStateUsing(fn (string $state): string => ImportResource::entityLabel($state))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('user.name')
                    ->label(__('imports.fields.user'))
                    ->placeholder(__('common.placeholders.empty'))
                    ->sortable(),

                TextColumn::make('total_rows')
                    ->label(__('imports.fields.total_rows'))
                    ->numeric()
                    ->sortable(),

                TextColumn::make('processed_rows')
                    ->label(__('imports.fields.processed_rows'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('successful_rows')
                    ->label(__('imports.fields.successful_rows'))
                    ->numeric()
                    ->color('success'),

                TextColumn::make('failed_rows_count')
                    ->label(__('imports.fields.failed_rows'))
                    ->state(fn (Import $record): int => $record->getFailedRowsCount())
                    ->numeric()
                    ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),

                TextColumn::make('status')
                    ->label(__('imports.fields.status'))
                    ->state(fn (Import $record): string => ImportResource::status($record))
                    ->formatStateUsing(fn (string $state): string => __('imports.statuses.'.$state))
                    ->badge()
                    ->color(fn (string $state): string => ImportResource::statusColor($state)),

                TextColumn::make('created_at')
                    ->label(__('imports.fields.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('completed_at')
                    ->label(__('imports.fields.completed_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder(__('common.placeholders.empty'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('importer')
                    ->label(__('imports.filters.entity'))
                    ->options(fn (): array => ImportResource::entityOptions())
                    ->multiple(),

                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label(__('imports.filters.from'))->native(false),
                        DatePicker::make('until')->label(__('imports.filters.until'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['from'] ?? null), fn (Builder $query): Builder => $query->where('created_at', '>=', app(SettingsRepository::class)->startOfOrganisationDay((string) $data['from'])))
                        ->when(filled($data['until'] ?? null), fn (Builder $query): Builder => $query->where('created_at', '<=', app(SettingsRepository::class)->endOfOrganisationDay((string) $data['until']))))
                    ->indicateUsing(fn (array $data): array => array_filter([
                        filled($data['from'] ?? null) ? __('imports.filters.from').': '.$data['from'] : null,
                        filled($data['until'] ?? null) ? __('imports.filters.until').': '.$data['until'] : null,
                    ])),
            ])
            ->recordActions([
                ViewAction::make()->label(__('imports.actions.view')),
            ])
            ->emptyStateHeading(__('imports.empty.heading'))
            ->emptyStateDescription(__('imports.empty.description'));
    }
}
