<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadStatuses;

use App\Enums\NavigationGroup;
use App\Filament\Resources\LeadStatuses\Pages\CreateLeadStatus;
use App\Filament\Resources\LeadStatuses\Pages\EditLeadStatus;
use App\Filament\Resources\LeadStatuses\Pages\ListLeadStatuses;
use App\Filament\Resources\LeadStatuses\Schemas\LeadStatusForm;
use App\Filament\Resources\LeadStatuses\Tables\LeadStatusesTable;
use App\Models\LeadStatus;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Configurable lead statuses (decisions D-7, A-4).
 */
final class LeadStatusResource extends Resource
{
    protected static ?string $model = LeadStatus::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 11;

    /** Global search covers leads, contacts, accounts, deals and tasks only (plan section 3.8, decision A-8). */
    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Settings;
    }

    public static function getNavigationLabel(): string
    {
        return __('lead_statuses.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('lead_statuses.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lead_statuses.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return LeadStatusForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeadStatusesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeadStatuses::route('/'),
            'create' => CreateLeadStatus::route('/create'),
            'edit' => EditLeadStatus::route('/{record}/edit'),
        ];
    }
}
