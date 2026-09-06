<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Services\Statistics\DashboardFilters;
use App\Services\Statistics\TaskMetrics;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The viewer's attention list (plan section 3.9): their open tasks that
 * are overdue or due today in the organisation's calendar day (D-8), the
 * overdue ones first with the due date in red, each row opening the task.
 * Fed by TaskMetrics inside the viewer's scope (D-4), whose boundary —
 * overdue is due before the start of today — also decides the colour, so
 * the header counts and the red dates agree. A personal list, so the page
 * filters do not apply.
 *
 * Filament passes `pageFilters` to every widget of a page that carries a
 * filters form (Page::getWidgetsSchemaComponents()), so the trait that
 * declares that property is required here even though this list ignores
 * the period: without it the property arrives as an unmatched Livewire
 * parameter and the page render fails.
 */
final class MyTasksTodayWidget extends TableWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Task::class);
    }

    public function table(Table $table): Table
    {
        $viewer = $this->viewer();
        $metrics = app(TaskMetrics::class);

        return $table
            ->heading(__('dashboard.tables.my_tasks_today'))
            ->description(__('dashboard.tables.my_tasks_today_description', [
                'today' => $metrics->myTasksTodayQuery($viewer)->count(),
                'overdue' => $metrics->myOverdueQuery($viewer)->count(),
            ]))
            ->query(static fn (): Builder => $metrics->myAttentionQuery($viewer))
            ->columns([
                TextColumn::make('title')
                    ->label(__('dashboard.tables.columns.title'))
                    ->weight('semibold'),

                TextColumn::make('kind')
                    ->label(__('dashboard.tables.columns.kind'))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('priority')
                    ->label(__('dashboard.tables.columns.priority'))
                    ->badge(),

                TextColumn::make('due_at')
                    ->label(__('dashboard.tables.columns.due_at'))
                    ->dateTime('Y-m-d H:i', timezone: DashboardFilters::timezone())
                    ->color(static fn (Task $record): string => $metrics->isOverdue($record) ? 'danger' : 'warning'),

                TextColumn::make('subject_record')
                    ->label(__('dashboard.tables.columns.subject'))
                    ->state(static fn (Task $record): ?string => $record->subjectLabel())
                    ->placeholder(__('common.placeholders.empty')),
            ])
            ->defaultSort('due_at')
            ->recordUrl(static fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record]))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading(__('dashboard.empty.tasks'))
            ->emptyStateDescription(__('dashboard.empty.tasks_description'));
    }

    private function viewer(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
