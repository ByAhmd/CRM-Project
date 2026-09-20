<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * The task list (decision A-10): "my" open tasks by default, then the open
 * tasks due today, the overdue, the upcoming, the completed and everything.
 * The "my" and "overdue" tabs carry a count so the numbers are visible
 * without opening them.
 */
final class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
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
                ->modifyQueryUsing(fn (Builder $query): Builder => self::open($query)
                    ->whereBetween('tasks.due_at', [now()->startOfDay(), now()->endOfDay()])),
            'overdue' => Tab::make(__('tasks.tabs.overdue'))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::overdue(self::open($query)))
                ->badge(fn (): ?int => self::count(self::overdue(self::open(TaskResource::getEloquentQuery()))))
                ->badgeColor('danger'),
            'upcoming' => Tab::make(__('tasks.tabs.upcoming'))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::open($query)
                    ->where('tasks.due_at', '>', now()->endOfDay())),
            'completed' => Tab::make(__('tasks.tabs.completed'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('tasks.status', TaskStatus::Completed->value)),
            'all' => Tab::make(__('tasks.tabs.all')),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'my';
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
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function overdue(Builder $query): Builder
    {
        return $query->where('tasks.due_at', '<', now());
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
