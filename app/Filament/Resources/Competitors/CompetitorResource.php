<?php

declare(strict_types=1);

namespace App\Filament\Resources\Competitors;

use App\Enums\NavigationGroup;
use App\Filament\Resources\Competitors\Pages\CreateCompetitor;
use App\Filament\Resources\Competitors\Pages\EditCompetitor;
use App\Filament\Resources\Competitors\Pages\ListCompetitors;
use App\Filament\Resources\Competitors\Schemas\CompetitorForm;
use App\Filament\Resources\Competitors\Tables\CompetitorsTable;
use App\Models\Competitor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Competitors named on deals (decisions A-4, D-8).
 */
final class CompetitorResource extends Resource
{
    protected static ?string $model = Competitor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?int $navigationSort = 22;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Settings;
    }

    public static function getNavigationLabel(): string
    {
        return __('competitors.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('competitors.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('competitors.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return CompetitorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompetitorsTable::configure($table);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompetitors::route('/'),
            'create' => CreateCompetitor::route('/create'),
            'edit' => EditCompetitor::route('/{record}/edit'),
        ];
    }
}
