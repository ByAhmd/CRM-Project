<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Schemas\ContactForm;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\LtrText;
use App\Filament\Support\OwnerSelect;
use App\Models\Contact;
use App\Models\User;
use App\Services\Contacts\ContactService;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The people at an account. Creating one here inherits the account and the
 * actor as owner; opening one leads to the contact's own page.
 *
 * The panel lists only the contacts the viewer may read (D-4): the account
 * being visible does not make every person at it visible. A change of owner
 * made in the edit modal is a reassignment and goes through
 * RecordAssignmentService (audit + notification).
 */
final class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('contacts.navigation.plural_model');
    }

    public static function getModelLabel(): string
    {
        return __('contacts.navigation.model');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->can('viewAny', Contact::class)
            && $user->can('view', $ownerRecord);
    }

    public function form(Schema $schema): Schema
    {
        return ContactForm::configure($schema, withAccount: false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('last_name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereIn('contacts.id', ContactResource::getEloquentQuery()->select('contacts.id'))
                ->with('owner'))
            // Phone budget: name, mobile and the primary flag stay at every
            // width; job title and e-mail step in from `md`, the owner from `lg`.
            ->columns([
                TextColumn::make('full_name')
                    ->label(__('contacts.fields.name'))
                    ->state(fn (Contact $record): string => $record->full_name)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('last_name', $direction)->orderBy('first_name', $direction))
                    ->weight('semibold'),
                TextColumn::make('job_title')->label(__('contacts.fields.job_title'))->placeholder(__('common.placeholders.empty'))->visibleFrom('md'),
                LtrText::column(TextColumn::make('email')->label(__('contacts.fields.email'))->placeholder(__('common.placeholders.empty'))->visibleFrom('md')),
                LtrText::column(TextColumn::make('mobile')->label(__('contacts.fields.mobile'))->placeholder(__('common.placeholders.empty'))),
                IconColumn::make('is_primary')->label(__('contacts.fields.is_primary'))->boolean(),
                TextColumn::make('owner.name')->label(__('contacts.fields.owner'))->placeholder(__('assignment.placeholders.unassigned'))->visibleFrom('lg'),
            ])
            ->defaultSort('is_primary', 'desc')
            ->headerActions([
                // The create form is the contact form, custom section included,
                // so its values are held aside and written once the row has an
                // id (D-9).
                CustomFieldActions::createAction(
                    CreateAction::make(),
                    mutate: function (array $data): array {
                        $data['created_by'] = auth()->id();
                        $data['owner_id'] = $data['owner_id'] ?? auth()->id();

                        return $data;
                    },
                    after: fn (Contact $record) => app(ContactService::class)->enforcePrimaryRule($record),
                ),
            ])
            // One three-dot menu per row instead of a wall of links; every
            // action keeps its own authorisation, and the menu hides itself
            // when the policy refuses every action in it.
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->url(fn (Contact $record): string => ContactResource::getUrl('view', ['record' => $record])),
                    CustomFieldActions::editAction(
                        EditAction::make()
                            // The owner is not saved as a plain attribute: a change
                            // is handed to RecordAssignmentService (D-4).
                            ->using(function (array $data, Contact $record): Contact {
                                $ownerId = OwnerSelect::pull($data, $record);

                                $record->update($data);

                                OwnerSelect::reassign($record, $ownerId);

                                return $record;
                            }),
                        after: fn (Contact $record) => app(ContactService::class)->enforcePrimaryRule($record),
                    ),
                ])
                    ->label(__('app.actions.row_actions'))
                    ->tooltip(__('app.actions.row_actions')),
            ])
            ->emptyStateHeading(__('contacts.empty.heading'))
            ->emptyStateDescription(__('contacts.empty.description'));
    }
}
