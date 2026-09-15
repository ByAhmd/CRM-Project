<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadScoringRules;

use App\Enums\NavigationGroup;
use App\Filament\Resources\LeadScoringRules\Pages\CreateLeadScoringRule;
use App\Filament\Resources\LeadScoringRules\Pages\EditLeadScoringRule;
use App\Filament\Resources\LeadScoringRules\Pages\ListLeadScoringRules;
use App\Filament\Resources\LeadScoringRules\Schemas\LeadScoringRuleForm;
use App\Filament\Resources\LeadScoringRules\Tables\LeadScoringRulesTable;
use App\Models\LeadScoringRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Lead scoring rules (decision D-7), under Settings.
 */
final class LeadScoringRuleResource extends Resource
{
    protected static ?string $model = LeadScoringRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 13;

    /** Global search covers leads, contacts, accounts, deals and tasks only (plan section 3.8, decision A-8). */
    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Settings;
    }

    public static function getNavigationLabel(): string
    {
        return __('lead_scoring_rules.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('lead_scoring_rules.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lead_scoring_rules.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return LeadScoringRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeadScoringRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeadScoringRules::route('/'),
            'create' => CreateLeadScoringRule::route('/create'),
            'edit' => EditLeadScoringRule::route('/{record}/edit'),
        ];
    }
}
