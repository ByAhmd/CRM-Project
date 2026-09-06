<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Tables;

use App\Enums\AccountType;
use App\Filament\Exports\AccountExporter;
use App\Filament\Support\ImportExportActions;
use App\Filament\Support\MergeActions;
use App\Filament\Support\OwnershipActions;
use App\Filament\Support\QueryBuilderFilters;
use App\Filament\Support\TagsSelect;
use App\Models\Account;
use App\Models\Industry;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                    ->toggleable(),

                TextColumn::make('owner.name')
                    ->label(__('accounts.fields.owner'))
                    ->placeholder(__('assignment.placeholders.unassigned'))
                    ->sortable(),

                TextColumn::make('phone')
                    ->label(__('accounts.fields.phone'))
                    ->searchable()
                    ->placeholder(__('common.placeholders.empty'))
                    ->extraAttributes(['dir' => 'ltr'])
                    ->toggleable(),

                TextColumn::make('email')
                    ->label(__('accounts.fields.email'))
                    ->searchable()
                    ->placeholder(__('common.placeholders.empty'))
                    ->extraAttributes(['dir' => 'ltr'])
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('contacts_count')
                    ->label(__('accounts.fields.contacts_count'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(),

                TagsSelect::column(),

                TextColumn::make('created_at')
                    ->label(__('accounts.fields.created_at'))
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ])
            ->filtersLayout(QueryBuilderFilters::layout())
            ->filtersFormWidth(QueryBuilderFilters::width())
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                OwnershipActions::assign(Account::permissionGroup()),
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
