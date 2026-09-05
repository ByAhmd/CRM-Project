<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadSources;

use App\Enums\NavigationGroup;
use App\Filament\Resources\LeadSources\Pages\CreateLeadSource;
use App\Filament\Resources\LeadSources\Pages\EditLeadSource;
use App\Filament\Resources\LeadSources\Pages\ListLeadSources;
use App\Filament\Resources\LeadSources\Schemas\LeadSourceForm;
use App\Filament\Resources\LeadSources\Tables\LeadSourcesTable;
use App\Models\LeadSource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Lead sources (decisions D-7, A-4).
 */
final class LeadSourceResource extends Resource
{
    protected static ?string $model = LeadSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Settings;
    }

    public static function getNavigationLabel(): string
    {
        return __('lead_sources.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('lead_sources.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lead_sources.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return LeadSourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeadSourcesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeadSources::route('/'),
            'create' => CreateLeadSource::route('/create'),
            'edit' => EditLeadSource::route('/{record}/edit'),
        ];
    }
}
