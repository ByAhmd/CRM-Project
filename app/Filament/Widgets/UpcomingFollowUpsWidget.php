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
 * The follow-ups, calls and meetings due in the coming days (plan section
 * 3.9), across the viewer's whole reach (D-4): a rep sees their own, a
 * manager the team's. Fed by TaskMetrics, soonest first, each row opening
 * the task. A fixed window — from the start of the organisation's day to
 * the end of the seventh — so the page filters do not apply. The due date is coloured with
 * the same organisation-day boundary it is printed in (isDueToday()), so
 * the highlight and the date never name different days.
 *
 * Filament passes `pageFilters` to every widget of a page that carries a
 * filters form (Page::getWidgetsSchemaComponents()), so the trait that
 * declares that property is required here even though this list ignores
 * the period: without it the property arrives as an unmatched Livewire
 * parameter and the page render fails.
 */
final class UpcomingFollowUpsWidget extends TableWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 6;

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
            ->heading(__('dashboard.tables.upcoming_follow_ups'))
            ->description(__('dashboard.tables.upcoming_follow_ups_description', ['days' => TaskMetrics::UPCOMING_DAYS]))
            ->query(static fn (): Builder => $metrics->upcomingFollowUpsQuery($viewer))
            ->columns([
                TextColumn::make('title')
                    ->label(__('dashboard.tables.columns.title'))
                    ->weight('semibold'),

                TextColumn::make('kind')
                    ->label(__('dashboard.tables.columns.kind'))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('due_at')
                    ->label(__('dashboard.tables.columns.due_at'))
                    ->dateTime('Y-m-d H:i', timezone: DashboardFilters::timezone())
                    ->color(static fn (Task $record): ?string => $metrics->isDueToday($record) ? 'warning' : null),

                TextColumn::make('assignee.name')
                    ->label(__('dashboard.tables.columns.assignee'))
                    ->placeholder(__('assignment.placeholders.unassigned')),

                TextColumn::make('subject_record')
                    ->label(__('dashboard.tables.columns.subject'))
                    ->state(static fn (Task $record): ?string => $record->subjectLabel())
                    ->placeholder(__('common.placeholders.empty')),
            ])
            ->defaultSort('due_at')
            ->recordUrl(static fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record]))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateIcon(Heroicon::OutlinedCalendarDays)
            ->emptyStateHeading(__('dashboard.empty.follow_ups'))
            ->emptyStateDescription(__('dashboard.empty.follow_ups_description'));
    }

    private function viewer(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
