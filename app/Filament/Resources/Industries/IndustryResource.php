<?php

declare(strict_types=1);

namespace App\Filament\Resources\Industries;

use App\Enums\NavigationGroup;
use App\Filament\Resources\Industries\Pages\CreateIndustry;
use App\Filament\Resources\Industries\Pages\EditIndustry;
use App\Filament\Resources\Industries\Pages\ListIndustries;
use App\Filament\Resources\Industries\Schemas\IndustryForm;
use App\Filament\Resources\Industries\Tables\IndustriesTable;
use App\Models\Industry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Industries lookup (decision A-4).
 */
final class IndustryResource extends Resource
{
    protected static ?string $model = Industry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Settings;
    }

    public static function getNavigationLabel(): string
    {
        return __('industries.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('industries.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('industries.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return IndustryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IndustriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIndustries::route('/'),
            'create' => CreateIndustry::route('/create'),
            'edit' => EditIndustry::route('/{record}/edit'),
        ];
    }
}
