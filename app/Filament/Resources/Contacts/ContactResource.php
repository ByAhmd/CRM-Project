<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts;

use App\Enums\NavigationGroup;
use App\Filament\Concerns\ScopesQueriesToVisibleRecords;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Contacts\RelationManagers\ContactActivitiesRelationManager;
use App\Filament\Resources\Contacts\RelationManagers\ContactAttachmentsRelationManager;
use App\Filament\Resources\Contacts\RelationManagers\ContactNotesRelationManager;
use App\Filament\Resources\Contacts\RelationManagers\ContactTasksRelationManager;
use App\Filament\Resources\Contacts\Schemas\ContactForm;
use App\Filament\Resources\Contacts\Schemas\ContactInfolist;
use App\Filament\Resources\Contacts\Tables\ContactsTable;
use App\Models\Contact;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Contacts — people at accounts (decision D-6). Visibility per D-4.
 */
final class ContactResource extends Resource
{
    use ScopesQueriesToVisibleRecords;

    protected static ?string $model = Contact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'last_name';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Contacts;
    }

    public static function getNavigationLabel(): string
    {
        return __('contacts.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('contacts.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('contacts.navigation.plural_model');
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof Contact ? $record->full_name : parent::getRecordTitle($record);
    }

    public static function form(Schema $schema): Schema
    {
        return ContactForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ContactInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContactsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return self::constrainToVisible(parent::getEloquentQuery())->with(['account', 'owner', 'tags']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [
            ContactActivitiesRelationManager::class,
            ContactTasksRelationManager::class,
            ContactNotesRelationManager::class,
            ContactAttachmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContacts::route('/'),
            'create' => CreateContact::route('/create'),
            'view' => ViewContact::route('/{record}'),
            'edit' => EditContact::route('/{record}/edit'),
        ];
    }

    public static function canGloballySearch(): bool
    {
        return self::canViewAny();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'email', 'mobile', 'phone', 'account.name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record instanceof Contact ? $record->full_name : parent::getGlobalSearchResultTitle($record);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return self::constrainToVisible(parent::getGlobalSearchEloquentQuery())->with(['account', 'owner']);
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        assert($record instanceof Contact);

        return array_filter([
            __('contacts.fields.account') => $record->account?->name,
            __('contacts.fields.job_title') => $record->job_title,
            __('contacts.fields.owner') => $record->owner?->name,
        ]);
    }
}
