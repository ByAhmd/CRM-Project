<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Exceptions\Tasks\InvalidTaskTransitionException;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\TaskActions;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Filament\Notifications\Notification;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The «تراجع» (undo) of a one-click completion (decision D-27).
 *
 * The completion toast of TaskActions::statusComplete() / quickComplete()
 * carries an "Undo" button that dispatches TaskActions::UNDO_COMPLETE_EVENT
 * with the task's id. A toast cannot run server code itself, so this
 * invisible component — mounted once on every page of the Tasks workspace
 * (TasksPanelProvider) — answers it. The id comes from the browser and is
 * trusted for nothing: the task is looked up among those the reader may
 * open (TaskResource's resolver-scoped query, deleted tasks excluded), the
 * policy's `reopen` answer decides (a completed task reopens for whoever may
 * report on it, D-14 amendment), and TaskService::undoCompletion() reopens
 * it only while it is still completed. A refusal is a danger toast, never
 * an error page. The screens are then told to re-render
 * (TaskActions::CHANGED_EVENT).
 */
final class TaskUndo extends Component
{
    #[On(TaskActions::UNDO_COMPLETE_EVENT)]
    public function undoComplete(mixed $task = null): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $id = is_int($task) || (is_string($task) && ctype_digit($task)) ? (int) $task : 0;
        $record = $id > 0 ? TaskResource::getEloquentQuery()->whereKey($id)->first() : null;

        // Deleted or unreadable since the toast appeared, or no longer the
        // reader's to reopen: a toast, not an error page over the screen. One
        // message for both, so it tells nothing about tasks the reader may not open.
        if (! $record instanceof Task || ! $user->can('reopen', $record)) {
            Notification::make()->title(__('tasks.validation.undo_unavailable'))->danger()->send();

            return;
        }

        try {
            app(TaskService::class)->undoCompletion($record, $user);
        } catch (InvalidTaskTransitionException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title(__('tasks.notifications.reopened'))->success()->send();

        $this->dispatch(TaskActions::CHANGED_EVENT, task: (int) $record->getKey());
    }

    public function render(): string
    {
        return '<div hidden></div>';
    }
}
