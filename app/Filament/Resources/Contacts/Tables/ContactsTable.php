<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\Tables;

use App\Enums\CustomFieldEntity;
use App\Filament\Exports\ContactExporter;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\EmailActions;
use App\Filament\Support\ImportExportActions;
use App\Filament\Support\LtrText;
use App\Filament\Support\MergeActions;
use App\Filament\Support\OwnershipActions;
use App\Filament\Support\QueryBuilderFilters;
use App\Filament\Support\TagsSelect;
use App\Models\Contact;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Every listed custom column reads the record's values, so the
            // relation is loaded once for the page instead of per cell (D-9).
            ->modifyQueryUsing(fn (Builder $query): Builder => CustomFieldsSchema::eagerLoad($query))
            // Phone budget: name, account and mobile stay at every width;
            // e-mail, job title and the primary flag step in from `md`, owner
            // and tags from `lg`. CSS breakpoints only — the cells stay in
            // the DOM.
            ->columns([
                TextColumn::make('full_name')
                    ->label(__('contacts.fields.name'))
                    ->state(fn (Contact $record): string => $record->full_name)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('last_name', $direction)->orderBy('first_name', $direction))
                    ->weight('semibold'),

                TextColumn::make('account.name')
                    ->label(__('contacts.fields.account'))
                    ->placeholder(__('common.placeholders.empty'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('job_title')
                    ->label(__('contacts.fields.job_title'))
                    ->placeholder(__('common.placeholders.empty'))
                    ->limit(40)
                    ->tooltip(fn (?string $state): ?string => $state !== null && mb_strlen($state) > 40 ? $state : null)
                    ->toggleable()
                    ->visibleFrom('md'),

                LtrText::column(
                    TextColumn::make('email')
                        ->label(__('contacts.fields.email'))
                        ->searchable()
                        ->placeholder(__('common.placeholders.empty'))
                        ->toggleable()
                        ->visibleFrom('md'),
                ),

                LtrText::column(
                    TextColumn::make('mobile')
                        ->label(__('contacts.fields.mobile'))
                        ->searchable()
                        ->placeholder(__('common.placeholders.empty')),
                ),

                IconColumn::make('is_primary')
                    ->label(__('contacts.fields.is_primary'))
                    ->boolean()
                    ->toggleable()
                    ->visibleFrom('md'),

                TextColumn::make('owner.name')
                    ->label(__('contacts.fields.owner'))
                    ->placeholder(__('assignment.placeholders.unassigned'))
                    ->sortable()
                    ->visibleFrom('lg'),

                TagsSelect::column()
                    ->visibleFrom('lg'),

                TextColumn::make('created_at')
                    ->label(__('contacts.fields.created_at'))
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                ...CustomFieldsSchema::tableColumns(CustomFieldEntity::Contact),
            ])
            ->defaultSort('last_name')
            ->filters([
                // Only the accounts the viewer may read are offered (D-4).
                SelectFilter::make('account')
                    ->label(__('contacts.filters.account'))
                    ->relationship('account', 'name', fn (Builder $query): Builder => $query->whereIn('accounts.id', AccountResource::getEloquentQuery()->select('accounts.id')))
                    ->searchable()
                    ->preload(),

                SelectFilter::make('owner_id')
                    ->label(__('contacts.filters.owner'))
                    ->options(function (): array {
                        $user = auth()->user();

                        return $user instanceof User
                            ? app(RecordVisibilityResolver::class)->assignableUsers($user, Contact::permissionGroup())->pluck('name', 'id')->all()
                            : [];
                    })
                    ->searchable(),

                TernaryFilter::make('is_primary')->label(__('contacts.filters.is_primary')),

                TagsSelect::filter(),

                TrashedFilter::make()->label(__('contacts.filters.trashed')),

                QueryBuilderFilters::forContacts(),

                ...CustomFieldsSchema::tableFilters(CustomFieldEntity::Contact),
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
                    EmailActions::send(),
                    OwnershipActions::assign(Contact::permissionGroup()),
                ])
                    ->label(__('app.actions.row_actions'))
                    ->tooltip(__('app.actions.row_actions')),
            ])
            ->toolbarActions([
                ImportExportActions::export(ContactExporter::class, Contact::class),
                BulkActionGroup::make([
                    ImportExportActions::exportBulk(ContactExporter::class, Contact::class),
                    OwnershipActions::assignBulk(Contact::permissionGroup()),
                    MergeActions::mergeBulk('contact', fn (Model $record): string => (string) $record->getAttribute('full_name')),
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                    RestoreBulkAction::make()->authorizeIndividualRecords('restore'),
                ]),
            ])
            ->emptyStateHeading(__('contacts.empty.heading'))
            ->emptyStateDescription(__('contacts.empty.description'));
    }
}
