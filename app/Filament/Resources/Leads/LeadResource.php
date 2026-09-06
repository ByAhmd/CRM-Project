<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads;

use App\Enums\NavigationGroup;
use App\Filament\Concerns\ScopesQueriesToVisibleRecords;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Leads\RelationManagers\LeadActivitiesRelationManager;
use App\Filament\Resources\Leads\RelationManagers\LeadAttachmentsRelationManager;
use App\Filament\Resources\Leads\RelationManagers\LeadNotesRelationManager;
use App\Filament\Resources\Leads\RelationManagers\LeadTasksRelationManager;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Filament\Resources\Leads\Schemas\LeadInfolist;
use App\Filament\Resources\Leads\Tables\LeadsTable;
use App\Models\Lead;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Leads (decision D-7). Visibility per D-4.
 */
final class LeadResource extends Resource
{
    use ScopesQueriesToVisibleRecords;

    protected static ?string $model = Lead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'last_name';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Sales;
    }

    public static function getNavigationLabel(): string
    {
        return __('leads.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('leads.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('leads.navigation.plural_model');
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof Lead ? $record->full_name : parent::getRecordTitle($record);
    }

    public static function form(Schema $schema): Schema
    {
        return LeadForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LeadInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeadsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return self::constrainToVisible(parent::getEloquentQuery())->with(['status', 'source', 'owner', 'tags']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [
            LeadActivitiesRelationManager::class,
            LeadTasksRelationManager::class,
            LeadNotesRelationManager::class,
            LeadAttachmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
            'create' => CreateLead::route('/create'),
            'view' => ViewLead::route('/{record}'),
            'edit' => EditLead::route('/{record}/edit'),
        ];
    }

    public static function canGloballySearch(): bool
    {
        return self::canViewAny();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'company_name', 'email', 'phone'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record instanceof Lead ? $record->full_name : parent::getGlobalSearchResultTitle($record);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return self::constrainToVisible(parent::getGlobalSearchEloquentQuery())->with(['status', 'owner']);
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        assert($record instanceof Lead);

        return array_filter([
            __('leads.fields.company_name') => $record->company_name,
            __('leads.fields.status') => $record->status?->getAttribute('display_name'),
            __('leads.fields.owner') => $record->owner?->name,
        ]);
    }
}
