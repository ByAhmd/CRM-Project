<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Tables;

use App\Enums\DealStatus;
use App\Enums\ForecastCategory;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Schemas\DealInfolist;
use App\Filament\Support\DealActions;
use App\Filament\Support\OwnershipActions;
use App\Filament\Support\TagsSelect;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

final class DealsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('deals.fields.title'))
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                TextColumn::make('account.name')
                    ->label(__('deals.fields.account'))
                    ->searchable()
                    ->placeholder(__('common.placeholders.empty'))
                    ->toggleable(),

                TextColumn::make('stage.display_name')
                    ->label(__('deals.fields.stage'))
                    ->badge()
                    ->color(fn (Deal $record): string => (string) ($record->stage?->color->value ?? 'gray')),

                TextColumn::make('status')
                    ->label(__('deals.fields.status'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label(__('deals.fields.amount'))
                    ->money(currency: fn (): string => DealResource::currency(), locale: fn (): string => app()->getLocale())
                    ->extraAttributes(['dir' => 'ltr'])
                    ->sortable(),

                TextColumn::make('weighted_amount')
                    ->label(__('deals.fields.weighted_amount'))
                    ->state(fn (Deal $record): string => $record->weighted_amount)
                    ->money(currency: fn (): string => DealResource::currency(), locale: fn (): string => app()->getLocale())
                    ->extraAttributes(['dir' => 'ltr'])
                    ->toggleable(),

                TextColumn::make('effective_probability')
                    ->label(__('deals.fields.effective_probability'))
                    ->state(fn (Deal $record): int => $record->effective_probability)
                    ->formatStateUsing(fn (int $state): string => Number::percentage($state, locale: app()->getLocale()))
                    ->toggleable(),

                TextColumn::make('forecast_category')
                    ->label(__('deals.fields.forecast_category'))
                    ->badge()
                    ->toggleable(),

                TextColumn::make('expected_close_date')
                    ->label(__('deals.fields.expected_close_date'))
                    ->date('Y-m-d')
                    ->color(fn (Deal $record): ?string => DealInfolist::isOverdue($record) ? 'danger' : null)
                    ->placeholder(__('common.placeholders.empty'))
                    ->sortable(),

                TextColumn::make('owner.name')
                    ->label(__('deals.fields.owner'))
                    ->placeholder(__('assignment.placeholders.unassigned'))
                    ->sortable(),

                TextColumn::make('products_count')
                    ->label(__('deals.fields.products_count'))
                    ->counts('products')
                    ->toggleable(isToggledHiddenByDefault: true),

                TagsSelect::column(),

                TextColumn::make('created_at')
                    ->label(__('deals.fields.created_at'))
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('pipeline_id')
                    ->label(__('deals.filters.pipeline'))
                    ->relationship('pipeline', Pipeline::localisedNameColumn())
                    ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) $record->getAttribute('display_name'))
                    ->multiple()
                    ->preload(),

                SelectFilter::make('stage_id')
                    ->label(__('deals.filters.stage'))
                    ->relationship('stage', PipelineStage::localisedNameColumn())
                    ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) $record->getAttribute('display_name'))
                    ->multiple()
                    ->preload(),

                SelectFilter::make('owner_id')
                    ->label(__('deals.filters.owner'))
                    ->options(function (): array {
                        $user = auth()->user();

                        return $user instanceof User
                            ? app(RecordVisibilityResolver::class)->assignableUsers($user, Deal::permissionGroup())->pluck('name', 'id')->all()
                            : [];
                    })
                    ->searchable(),

                SelectFilter::make('forecast_category')
                    ->label(__('deals.filters.forecast_category'))
                    ->options(ForecastCategory::class)
                    ->multiple(),

                SelectFilter::make('status')
                    ->label(__('deals.filters.status'))
                    ->options(DealStatus::class)
                    ->multiple(),

                Filter::make('expected_close_date')
                    ->schema([
                        DatePicker::make('from')->label(__('deals.filters.expected_close_from'))->native(false),
                        DatePicker::make('until')->label(__('deals.filters.expected_close_until'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['from'] ?? null), fn (Builder $query): Builder => $query->whereDate('expected_close_date', '>=', $data['from']))
                        ->when(filled($data['until'] ?? null), fn (Builder $query): Builder => $query->whereDate('expected_close_date', '<=', $data['until'])))
                    ->indicateUsing(fn (array $data): array => array_filter([
                        filled($data['from'] ?? null) ? __('deals.filters.expected_close_from').': '.$data['from'] : null,
                        filled($data['until'] ?? null) ? __('deals.filters.expected_close_until').': '.$data['until'] : null,
                    ])),

                TagsSelect::filter(),

                TrashedFilter::make()->label(__('deals.filters.trashed')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DealActions::changeStage(),
                DealActions::markWon(),
                DealActions::markLost(),
                DealActions::reopen(),
                OwnershipActions::assign(Deal::permissionGroup()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    OwnershipActions::assignBulk(Deal::permissionGroup()),
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                    RestoreBulkAction::make()->authorizeIndividualRecords('restore'),
                ]),
            ])
            ->emptyStateHeading(__('deals.empty.heading'))
            ->emptyStateDescription(__('deals.empty.description'));
    }
}
