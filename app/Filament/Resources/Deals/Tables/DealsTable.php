<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Tables;

use App\Enums\CustomFieldEntity;
use App\Enums\DealStatus;
use App\Enums\ForecastCategory;
use App\Filament\Exports\DealExporter;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Schemas\DealInfolist;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\DealActions;
use App\Filament\Support\ImportExportActions;
use App\Filament\Support\LtrText;
use App\Filament\Support\OwnershipActions;
use App\Filament\Support\QueryBuilderFilters;
use App\Filament\Support\TagsSelect;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Filament\Actions\ActionGroup;
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
            // Every listed custom column reads the record's values, so the
            // relation is loaded once for the page instead of per cell (D-9).
            ->modifyQueryUsing(fn (Builder $query): Builder => CustomFieldsSchema::eagerLoad($query))
            // Phone budget: title, stage, status and amount stay at every
            // width; the account and close date step in from `md`, forecasting
            // detail, owner and dates from `lg`. CSS breakpoints only — the
            // cells stay in the DOM.
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
                    ->toggleable()
                    ->visibleFrom('md'),

                TextColumn::make('stage.display_name')
                    ->label(__('deals.fields.stage'))
                    ->badge()
                    ->color(fn (Deal $record): string => (string) ($record->stage?->color->value ?? 'gray')),

                TextColumn::make('status')
                    ->label(__('deals.fields.status'))
                    ->badge()
                    ->sortable(),

                LtrText::column(
                    TextColumn::make('amount')
                        ->label(__('deals.fields.amount'))
                        ->money(currency: fn (): string => DealResource::currency(), locale: fn (): string => app()->getLocale())
                        ->sortable(),
                ),

                LtrText::column(
                    TextColumn::make('weighted_amount')
                        ->label(__('deals.fields.weighted_amount'))
                        ->state(fn (Deal $record): string => $record->weighted_amount)
                        ->money(currency: fn (): string => DealResource::currency(), locale: fn (): string => app()->getLocale())
                        ->toggleable()
                        ->visibleFrom('lg'),
                ),

                TextColumn::make('effective_probability')
                    ->label(__('deals.fields.effective_probability'))
                    ->state(fn (Deal $record): int => $record->effective_probability)
                    ->formatStateUsing(fn (int $state): string => Number::percentage($state, locale: app()->getLocale()))
                    ->toggleable()
                    ->visibleFrom('lg'),

                TextColumn::make('forecast_category')
                    ->label(__('deals.fields.forecast_category'))
                    ->badge()
                    ->toggleable()
                    ->visibleFrom('lg'),

                TextColumn::make('expected_close_date')
                    ->label(__('deals.fields.expected_close_date'))
                    ->date('Y-m-d')
                    ->color(fn (Deal $record): ?string => DealInfolist::isOverdue($record) ? 'danger' : null)
                    ->placeholder(__('common.placeholders.empty'))
                    ->sortable()
                    ->visibleFrom('md'),

                TextColumn::make('owner.name')
                    ->label(__('deals.fields.owner'))
                    ->placeholder(__('assignment.placeholders.unassigned'))
                    ->sortable()
                    ->visibleFrom('lg'),

                TextColumn::make('products_count')
                    ->label(__('deals.fields.products_count'))
                    ->counts('products')
                    ->toggleable(isToggledHiddenByDefault: true),

                TagsSelect::column()
                    ->visibleFrom('lg'),

                TextColumn::make('created_at')
                    ->label(__('deals.fields.created_at'))
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable()
                    ->visibleFrom('lg'),

                ...CustomFieldsSchema::tableColumns(CustomFieldEntity::Deal),
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
                        ->when(filled($data['from'] ?? null), fn (Builder $query): Builder => $query->where('expected_close_date', '>=', $data['from']))
                        ->when(filled($data['until'] ?? null), fn (Builder $query): Builder => $query->where('expected_close_date', '<=', $data['until'])))
                    ->indicateUsing(fn (array $data): array => array_filter([
                        filled($data['from'] ?? null) ? __('deals.filters.expected_close_from').': '.$data['from'] : null,
                        filled($data['until'] ?? null) ? __('deals.filters.expected_close_until').': '.$data['until'] : null,
                    ])),

                TagsSelect::filter(),

                TrashedFilter::make()->label(__('deals.filters.trashed')),

                QueryBuilderFilters::forDeals(),

                ...CustomFieldsSchema::tableFilters(CustomFieldEntity::Deal),
            ])
            ->filtersLayout(QueryBuilderFilters::layout())
            ->filtersFormWidth(QueryBuilderFilters::width())
            // One three-dot menu per row instead of a wall of links; every
            // action keeps its own authorisation, and the menu hides itself
            // when the policy refuses every action in it.
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    // The custom section is part of the entity form, so an edit
                    // modal built from it pre-fills and writes the values too (D-9).
                    CustomFieldActions::editAction(EditAction::make()),
                    DealActions::changeStage(),
                    DealActions::markWon(),
                    DealActions::markLost(),
                    DealActions::reopen(),
                    OwnershipActions::assign(Deal::permissionGroup()),
                ])
                    ->label(__('app.actions.row_actions'))
                    ->tooltip(__('app.actions.row_actions')),
            ])
            ->toolbarActions([
                ImportExportActions::export(DealExporter::class, Deal::class),
                BulkActionGroup::make([
                    ImportExportActions::exportBulk(DealExporter::class, Deal::class),
                    OwnershipActions::assignBulk(Deal::permissionGroup()),
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                    RestoreBulkAction::make()->authorizeIndividualRecords('restore'),
                ]),
            ])
            ->emptyStateHeading(__('deals.empty.heading'))
            ->emptyStateDescription(__('deals.empty.description'));
    }
}
