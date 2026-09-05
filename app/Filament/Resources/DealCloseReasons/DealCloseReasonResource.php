<?php

declare(strict_types=1);

namespace App\Filament\Resources\DealCloseReasons;

use App\Enums\NavigationGroup;
use App\Filament\Resources\DealCloseReasons\Pages\CreateDealCloseReason;
use App\Filament\Resources\DealCloseReasons\Pages\EditDealCloseReason;
use App\Filament\Resources\DealCloseReasons\Pages\ListDealCloseReasons;
use App\Filament\Resources\DealCloseReasons\Schemas\DealCloseReasonForm;
use App\Filament\Resources\DealCloseReasons\Tables\DealCloseReasonsTable;
use App\Models\DealCloseReason;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Deal close reasons (decisions D-8, A-4).
 */
final class DealCloseReasonResource extends Resource
{
    protected static ?string $model = DealCloseReason::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?int $navigationSort = 21;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Settings;
    }

    public static function getNavigationLabel(): string
    {
        return __('close_reasons.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('close_reasons.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('close_reasons.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return DealCloseReasonForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DealCloseReasonsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDealCloseReasons::route('/'),
            'create' => CreateDealCloseReason::route('/create'),
            'edit' => EditDealCloseReason::route('/{record}/edit'),
        ];
    }
}
