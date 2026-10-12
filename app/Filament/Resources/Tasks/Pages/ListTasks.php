<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Enums\MyWorkView;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\TaskActions;
use App\Models\Task;
use App\Models\User;
use App\Services\Settings\OrganisationCalendar;
use App\Services\Tasks\MyWorkFeed;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

/**
 * The task list (decisions A-10, D-27): "my" open tasks by default, then the
 * open tasks due today, the overdue, the upcoming, the completed and
 * everything. The "my" and "overdue" tabs carry a count so the numbers are
 * visible without opening them.
 *
 * "Today" is the organisation's calendar day (OrganisationCalendar, A-19):
 * due today means due inside that day at any hour — the relative dates on
 * the rows read «اليوم 17:00» for it — overdue means due before it, and
 * upcoming means due after it. The bounds are computed in PHP and compared
 * with the raw due_at column, so its index serves every tab.
 *
 * Quick add (D-27) is the primary header action — the N shortcut opens it
 * through `?action=quickAdd` — with the full form one click further. The
 * list re-renders when a «تراجع» (undo) reopens a task (TaskUndo).
 */
final class ListTasks extends ListRecords
{
    /** The tabs, in order; the table's empty state has one message per tab. */
    public const array TABS = ['my', 'today', 'overdue', 'upcoming', 'completed', 'all'];

    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Header action `quickAdd`: the N shortcut opens it in place (data-crm-shortcut), the phone bar's «+» through `?action=quickAdd`.
            TaskActions::quickAdd(),
            CreateAction::make()
                ->label(__('tasks.list_ux.actions.full_form'))
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->color('gray'),
        ];
    }

    /** A «تراجع» (undo) elsewhere on the page changed a task: render the rows again. */
    #[On(TaskActions::CHANGED_EVENT)]
    public function refreshTasks(): void
    {
        // Nothing to change: the call itself re-renders the table from the database.
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'my' => Tab::make(__('tasks.tabs.my'))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::mine(self::open($query)))
                ->badge(fn (): ?int => self::count(self::mine(self::open(TaskResource::getEloquentQuery())))),
            'today' => Tab::make(__('tasks.tabs.today'))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::dueToday(self::open($query))),
            'overdue' => Tab::make(__('tasks.tabs.overdue'))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::overdue(self::open($query)))
                ->badge(fn (): ?int => self::count(self::overdue(self::open(TaskResource::getEloquentQuery()))))
                ->badgeColor('danger'),
            'upcoming' => Tab::make(__('tasks.tabs.upcoming'))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::upcoming(self::open($query))),
            'completed' => Tab::make(__('tasks.tabs.completed'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('tasks.status', TaskStatus::Completed->value)),
            'all' => Tab::make(__('tasks.tabs.all')),
        ];
    }

    /**
     * «مهامي» for a reader with open work of their own (or who sees no one
     * else's), otherwise «الكل»: the same rule that opens My Work on the team's
     * tasks, so whoever hands work out never opens the list on an empty tab
     * (D-27 amendment, the owner's report 2026-10-12).
     */
    public function getDefaultActiveTab(): string
    {
        $reader = auth()->user();

        return ! $reader instanceof User || app(MyWorkFeed::class)->resolveView($reader, null) === MyWorkView::Mine ? 'my' : 'all';
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function open(Builder $query): Builder
    {
        return $query->whereIn('tasks.status', Task::openStatusValues());
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function mine(Builder $query): Builder
    {
        return $query->where('tasks.assignee_id', auth()->id());
    }

    /**
     * Due inside the organisation's today, at any hour: [start of today, start of tomorrow).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function dueToday(Builder $query): Builder
    {
        $calendar = app(OrganisationCalendar::class);
        [$start, $end] = $calendar->dayRange($calendar->today());

        return $query->where('tasks.due_at', '>=', $start)->where('tasks.due_at', '<', $end);
    }

    /**
     * Due before the organisation's today began.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function overdue(Builder $query): Builder
    {
        $calendar = app(OrganisationCalendar::class);

        return $query->where('tasks.due_at', '<', $calendar->stored($calendar->today()));
    }

    /**
     * Due from the organisation's tomorrow on.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function upcoming(Builder $query): Builder
    {
        $calendar = app(OrganisationCalendar::class);

        return $query->where('tasks.due_at', '>=', $calendar->stored($calendar->tomorrow()));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private static function count(Builder $query): ?int
    {
        $count = $query->count();

        return $count > 0 ? $count : null;
    }
}
