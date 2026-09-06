<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\Tables;

use App\Enums\CustomFieldEntity;
use App\Filament\Exports\ContactExporter;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\EmailActions;
use App\Filament\Support\ImportExportActions;
use App\Filament\Support\MergeActions;
use App\Filament\Support\OwnershipActions;
use App\Filament\Support\QueryBuilderFilters;
use App\Filament\Support\TagsSelect;
use App\Models\Contact;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
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
                    ->toggleable(),

                TextColumn::make('email')
                    ->label(__('contacts.fields.email'))
                    ->searchable()
                    ->placeholder(__('common.placeholders.empty'))
                    ->extraAttributes(['dir' => 'ltr'])
                    ->toggleable(),

                TextColumn::make('mobile')
                    ->label(__('contacts.fields.mobile'))
                    ->searchable()
                    ->placeholder(__('common.placeholders.empty'))
                    ->extraAttributes(['dir' => 'ltr']),

                IconColumn::make('is_primary')
                    ->label(__('contacts.fields.is_primary'))
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('owner.name')
                    ->label(__('contacts.fields.owner'))
                    ->placeholder(__('assignment.placeholders.unassigned'))
                    ->sortable(),

                TagsSelect::column(),

                TextColumn::make('created_at')
                    ->label(__('contacts.fields.created_at'))
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                ...CustomFieldsSchema::tableColumns(CustomFieldEntity::Contact),
            ])
            ->defaultSort('last_name')
            ->filters([
                SelectFilter::make('account')
                    ->label(__('contacts.filters.account'))
                    ->relationship('account', 'name')
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
            ->recordActions([
                ViewAction::make(),
                // The custom section is part of the entity form, so an edit
                // modal built from it pre-fills and writes the values too (D-9).
                CustomFieldActions::editAction(EditAction::make()),
                EmailActions::send(),
                OwnershipActions::assign(Contact::permissionGroup()),
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
