<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts;

use App\Enums\NavigationGroup;
use App\Filament\Concerns\ScopesQueriesToVisibleRecords;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Accounts\RelationManagers\DealsRelationManager;
use App\Filament\Resources\Accounts\Schemas\AccountForm;
use App\Filament\Resources\Accounts\Schemas\AccountInfolist;
use App\Filament\Resources\Accounts\Tables\AccountsTable;
use App\Models\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Accounts — companies and customers (decision D-6). Visibility per D-4.
 */
final class AccountResource extends Resource
{
    use ScopesQueriesToVisibleRecords;

    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Contacts;
    }

    public static function getNavigationLabel(): string
    {
        return __('accounts.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('accounts.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('accounts.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return AccountForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AccountInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return self::constrainToVisible(parent::getEloquentQuery())
            ->with(['industry', 'owner', 'tags'])
            ->withCount('contacts');
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
            DealsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccounts::route('/'),
            'create' => CreateAccount::route('/create'),
            'view' => ViewAccount::route('/{record}'),
            'edit' => EditAccount::route('/{record}/edit'),
        ];
    }

    // --- Global search (permission-aware through the visibility scope) ---

    public static function canGloballySearch(): bool
    {
        return self::canViewAny();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'phone', 'city'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return self::constrainToVisible(parent::getGlobalSearchEloquentQuery())->with(['owner', 'industry']);
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        assert($record instanceof Account);

        return array_filter([
            __('accounts.fields.type') => $record->type->getLabel(),
            __('accounts.fields.owner') => $record->owner?->name,
            __('accounts.fields.industry') => $record->industry?->getAttribute('display_name'),
        ]);
    }
}
