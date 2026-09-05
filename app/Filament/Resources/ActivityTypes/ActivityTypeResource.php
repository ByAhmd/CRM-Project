<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityTypes;

use App\Enums\NavigationGroup;
use App\Filament\Resources\ActivityTypes\Pages\CreateActivityType;
use App\Filament\Resources\ActivityTypes\Pages\EditActivityType;
use App\Filament\Resources\ActivityTypes\Pages\ListActivityTypes;
use App\Filament\Resources\ActivityTypes\Schemas\ActivityTypeForm;
use App\Filament\Resources\ActivityTypes\Tables\ActivityTypesTable;
use App\Models\ActivityType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Configurable activity types (decision A-4).
 */
final class ActivityTypeResource extends Resource
{
    protected static ?string $model = ActivityType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Settings;
    }

    public static function getNavigationLabel(): string
    {
        return __('activity_types.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('activity_types.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('activity_types.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return ActivityTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActivityTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityTypes::route('/'),
            'create' => CreateActivityType::route('/create'),
            'edit' => EditActivityType::route('/{record}/edit'),
        ];
    }
}
