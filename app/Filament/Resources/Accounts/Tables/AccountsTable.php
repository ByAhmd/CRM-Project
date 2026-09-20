<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Tables;

use App\Enums\AccountType;
use App\Enums\CustomFieldEntity;
use App\Filament\Exports\AccountExporter;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\ImportExportActions;
use App\Filament\Support\LtrText;
use App\Filament\Support\MergeActions;
use App\Filament\Support\OwnershipActions;
use App\Filament\Support\QueryBuilderFilters;
use App\Filament\Support\TagsSelect;
use App\Models\Account;
use App\Models\Industry;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Every listed custom column reads the record's values, so the
            // relation is loaded once for the page instead of per cell (D-9).
            ->modifyQueryUsing(fn (Builder $query): Builder => CustomFieldsSchema::eagerLoad($query))
            // Phone budget: name, type and the contact count stay at every
            // width; industry and owner step in from `md`, phone and tags from
            // `lg`. CSS breakpoints only — the cells stay in the DOM.
            ->columns([
                TextColumn::make('name')
                    ->label(__('accounts.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                TextColumn::make('type')
                    ->label(__('accounts.fields.type'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('industry.display_name')
                    ->label(__('accounts.fields.industry'))
                    ->placeholder(__('common.placeholders.empty'))
                    ->toggleable()
                    ->visibleFrom('md'),

                TextColumn::make('owner.name')
                    ->label(__('accounts.fields.owner'))
                    ->placeholder(__('assignment.placeholders.unassigned'))
                    ->sortable()
                    ->visibleFrom('md'),

                LtrText::column(
                    TextColumn::make('phone')
                        ->label(__('accounts.fields.phone'))
                        ->searchable()
                        ->placeholder(__('common.placeholders.empty'))
                        ->toggleable()
                        ->visibleFrom('lg'),
                ),

                LtrText::column(
                    TextColumn::make('email')
                        ->label(__('accounts.fields.email'))
                        ->searchable()
                        ->placeholder(__('common.placeholders.empty'))
                        ->toggleable(isToggledHiddenByDefault: true),
                ),

                TextColumn::make('contacts_count')
                    ->label(__('accounts.fields.contacts_count'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(),

                TagsSelect::column()
                    ->visibleFrom('lg'),

                TextColumn::make('created_at')
                    ->label(__('accounts.fields.created_at'))
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                ...CustomFieldsSchema::tableColumns(CustomFieldEntity::Account),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('type')
                    ->label(__('accounts.filters.type'))
                    ->options(AccountType::class)
                    ->multiple(),

                SelectFilter::make('industry')
                    ->label(__('accounts.filters.industry'))
                    ->relationship('industry', Industry::localisedNameColumn())
                    ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) $record->getAttribute('display_name'))
                    ->preload(),

                SelectFilter::make('owner_id')
                    ->label(__('accounts.filters.owner'))
                    ->options(function (): array {
                        $user = auth()->user();

                        return $user instanceof User
                            ? app(RecordVisibilityResolver::class)->assignableUsers($user, Account::permissionGroup())->pluck('name', 'id')->all()
                            : [];
                    })
                    ->searchable(),

                TagsSelect::filter(),

                TrashedFilter::make()->label(__('accounts.filters.trashed')),

                QueryBuilderFilters::forAccounts(),

                ...CustomFieldsSchema::tableFilters(CustomFieldEntity::Account),
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
                    OwnershipActions::assign(Account::permissionGroup()),
                ])
                    ->label(__('app.actions.row_actions'))
                    ->tooltip(__('app.actions.row_actions')),
            ])
            ->toolbarActions([
                ImportExportActions::export(AccountExporter::class, Account::class),
                BulkActionGroup::make([
                    ImportExportActions::exportBulk(AccountExporter::class, Account::class),
                    OwnershipActions::assignBulk(Account::permissionGroup()),
                    MergeActions::mergeBulk('account', fn (Model $record): string => (string) $record->getAttribute('name')),
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                    RestoreBulkAction::make()->authorizeIndividualRecords('restore'),
                ]),
            ])
            ->emptyStateHeading(__('accounts.empty.heading'))
            ->emptyStateDescription(__('accounts.empty.description'));
    }
}
