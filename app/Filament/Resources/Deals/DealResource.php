<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals;

use App\Enums\NavigationGroup;
use App\Filament\Concerns\ScopesQueriesToVisibleRecords;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\CompetitorsRelationManager;
use App\Filament\Resources\Deals\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Deals\Schemas\DealForm;
use App\Filament\Resources\Deals\Schemas\DealInfolist;
use App\Filament\Resources\Deals\Tables\DealsTable;
use App\Models\Deal;
use App\Services\Settings\SettingsRepository;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Number;

/**
 * Deals (decisions D-6, D-8). Visibility per D-4; the stage and the close
 * columns change only through the actions, which call the workflow services.
 *
 * @extends resource<Deal>
 */
final class DealResource extends Resource
{
    use ScopesQueriesToVisibleRecords;

    protected static ?string $model = Deal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Sales;
    }

    public static function getNavigationLabel(): string
    {
        return __('deals.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('deals.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('deals.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return DealForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DealInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DealsTable::configure($table);
    }

    /**
     * @return Builder<Deal>
     */
    public static function getEloquentQuery(): Builder
    {
        return self::constrainToVisible(parent::getEloquentQuery())
            ->with(['account', 'stage', 'pipeline', 'owner', 'tags']);
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
            CompetitorsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeals::route('/'),
            'create' => CreateDeal::route('/create'),
            'view' => ViewDeal::route('/{record}'),
            'edit' => EditDeal::route('/{record}/edit'),
        ];
    }

    // --- Global search (permission-aware through the visibility scope) ---

    public static function canGloballySearch(): bool
    {
        return self::canViewAny();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'account.name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return self::constrainToVisible(parent::getGlobalSearchEloquentQuery())->with(['account', 'stage']);
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        assert($record instanceof Deal);

        return array_filter([
            __('deals.fields.account') => $record->account?->name,
            __('deals.fields.stage') => $record->stage?->getAttribute('display_name'),
            __('deals.fields.amount') => Number::currency((float) $record->amount, $record->currency, app()->getLocale()),
        ]);
    }

    /** The organisation currency every deal amount is shown in (D-8). */
    public static function currency(): string
    {
        return app(SettingsRepository::class)->currency();
    }
}
