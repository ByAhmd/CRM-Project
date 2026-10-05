<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskTemplates;

use App\Enums\NavigationGroup;
use App\Filament\Concerns\BuildsUrlsInOwningPanel;
use App\Filament\Resources\TaskTemplates\Pages\CreateTaskTemplate;
use App\Filament\Resources\TaskTemplates\Pages\EditTaskTemplate;
use App\Filament\Resources\TaskTemplates\Pages\ListTaskTemplates;
use App\Filament\Resources\TaskTemplates\Schemas\TaskTemplateForm;
use App\Filament\Resources\TaskTemplates\Tables\TaskTemplatesTable;
use App\Models\TaskTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Routine tasks (decision D-22): the recurring task templates that hand
 * routine weekly or monthly work out on its day, listed right after the
 * shared tasks board. Visible to holders of `task.assign` only
 * (TaskTemplatePolicy); every write goes through RoutineTaskService.
 *
 * @extends resource<TaskTemplate>
 */
final class TaskTemplateResource extends Resource
{
    use BuildsUrlsInOwningPanel;

    protected static ?string $model = TaskTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    /** Right after the tasks board (25) and before the calendar (30). */
    protected static ?int $navigationSort = 26;

    /** Global search covers leads, contacts, accounts, deals and tasks only (plan section 3.8, decision A-8). */
    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Tasks;
    }

    public static function getNavigationLabel(): string
    {
        return __('task_templates.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('task_templates.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('task_templates.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return TaskTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaskTemplatesTable::configure($table);
    }

    /**
     * The assignees and the assigner are listed on every row: loaded with the
     * page, never per row.
     *
     * @return Builder<TaskTemplate>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['assignees', 'assigner']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaskTemplates::route('/'),
            'create' => CreateTaskTemplate::route('/create'),
            'edit' => EditTaskTemplate::route('/{record}/edit'),
        ];
    }
}
