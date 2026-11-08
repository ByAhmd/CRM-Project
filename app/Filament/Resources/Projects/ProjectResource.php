<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects;

use App\Enums\NavigationGroup;
use App\Filament\Concerns\BuildsUrlsInOwningPanel;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use App\Models\Project;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Projects (decision D-30): the partitions of the customer base, managed
 * under Administration → Settings like every lookup (A-4). The writes go
 * through ProjectService so exactly one project stays the default.
 */
final class ProjectResource extends Resource
{
    use BuildsUrlsInOwningPanel;

    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    /** First in Settings: the projects partition everything the other lookups describe. */
    protected static ?int $navigationSort = 5;

    /** Global search covers leads, contacts, accounts, deals and tasks only (plan section 3.8, decision A-8). */
    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Settings;
    }

    public static function getNavigationLabel(): string
    {
        return __('projects.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('projects.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('projects.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return ProjectForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}
