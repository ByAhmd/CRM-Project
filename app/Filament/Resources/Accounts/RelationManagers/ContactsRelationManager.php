<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Schemas\ContactForm;
use App\Models\Contact;
use App\Models\User;
use App\Services\Contacts\ContactService;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The people at an account. Creating one here inherits the account and the
 * actor as owner; opening one leads to the contact's own page.
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

        return $user instanceof User && $user->can('viewAny', Contact::class);
    }

    public function form(Schema $schema): Schema
    {
        return ContactForm::configure($schema, withAccount: false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('last_name')
            ->columns([
                TextColumn::make('full_name')
                    ->label(__('contacts.fields.name'))
                    ->state(fn (Contact $record): string => $record->full_name)
                    ->searchable(['first_name', 'last_name'])
                    ->weight('semibold'),
                TextColumn::make('job_title')->label(__('contacts.fields.job_title'))->placeholder(__('common.placeholders.empty')),
                TextColumn::make('email')->label(__('contacts.fields.email'))->placeholder(__('common.placeholders.empty'))->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('mobile')->label(__('contacts.fields.mobile'))->placeholder(__('common.placeholders.empty'))->extraAttributes(['dir' => 'ltr']),
                IconColumn::make('is_primary')->label(__('contacts.fields.is_primary'))->boolean(),
                TextColumn::make('owner.name')->label(__('contacts.fields.owner'))->placeholder(__('assignment.placeholders.unassigned')),
            ])
            ->defaultSort('is_primary', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        $data['created_by'] = auth()->id();
                        $data['owner_id'] = $data['owner_id'] ?? auth()->id();

                        return $data;
                    })
                    ->after(fn (Contact $record) => app(ContactService::class)->enforcePrimaryRule($record)),
            ])
            ->recordActions([
                ViewAction::make()->url(fn (Contact $record): string => ContactResource::getUrl('view', ['record' => $record])),
                EditAction::make()->after(fn (Contact $record) => app(ContactService::class)->enforcePrimaryRule($record)),
            ])
            ->emptyStateHeading(__('contacts.empty.heading'))
            ->emptyStateDescription(__('contacts.empty.description'));
    }
}
