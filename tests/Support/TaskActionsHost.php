<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\DueShortcut;
use App\Filament\Support\TaskActions;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;

/**
 * A bare Livewire page holding the D-27 quick controls of TaskActions over
 * one task, so the tests exercise each action exactly as a Tasks-workspace
 * screen mounts it — `->record()` resolving the task on every request, as a
 * board or a card list does — without depending on any one screen.
 */
final class TaskActionsHost extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public int $taskId = 0;

    public function statusStartAction(): Action
    {
        return TaskActions::statusStart()->record(fn (): ?Task => $this->task());
    }

    public function statusCompleteAction(): Action
    {
        return TaskActions::statusComplete()->record(fn (): ?Task => $this->task());
    }

    public function quickCompleteAction(): Action
    {
        return TaskActions::quickComplete()->record(fn (): ?Task => $this->task());
    }

    public function statusReopenAction(): Action
    {
        return TaskActions::statusReopen()->record(fn (): ?Task => $this->task());
    }

    public function statusCancelAction(): Action
    {
        return TaskActions::statusCancel()->record(fn (): ?Task => $this->task());
    }

    public function changeStatusAction(): Action
    {
        return TaskActions::changeStatus()->record(fn (): ?Task => $this->task());
    }

    public function rescheduleTodayAction(): Action
    {
        return TaskActions::rescheduleTo(DueShortcut::Today)->record(fn (): ?Task => $this->task());
    }

    public function rescheduleTomorrowAction(): Action
    {
        return TaskActions::rescheduleTo(DueShortcut::Tomorrow)->record(fn (): ?Task => $this->task());
    }

    public function rescheduleNextWeekAction(): Action
    {
        return TaskActions::rescheduleTo(DueShortcut::NextWeek)->record(fn (): ?Task => $this->task());
    }

    public function reschedulePickAction(): Action
    {
        return TaskActions::reschedulePick()->record(fn (): ?Task => $this->task());
    }

    public function rescheduleAction(): Action
    {
        return TaskActions::reschedule()->record(fn (): ?Task => $this->task());
    }

    public function quickAddAction(): Action
    {
        return TaskActions::quickAdd();
    }

    public function task(): ?Task
    {
        return Task::query()->withTrashed()->find($this->taskId);
    }

    public function render(): string
    {
        return '<div><x-filament-actions::modals /></div>';
    }
}
