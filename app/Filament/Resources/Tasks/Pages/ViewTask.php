<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Enums\TaskStatus;
use App\Exceptions\Tasks\InvalidTaskTransitionException;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\OwnershipActions;
use App\Filament\Support\TaskActions;
use App\Livewire\Tasks\TaskComposer;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * One task, on the Tasks workspace's two-column page (decisions A-10, D-14,
 * D-17, D-20, D-25, D-27).
 *
 * The header holds ONE primary status button, chosen by the task's state and
 * the reader's policy: Start on a pending task, Complete on one in progress
 * (one click; the D-25 warning first when checklist items are open, then a
 * toast with «تراجع»), Reopen on a closed one. Everything else sits in one
 * «المزيد» menu — start or complete with a note, post update and the
 * checklist dialog (the fallbacks of the inline controls), comment, cancel,
 * assign, edit, delete, restore — each still authorised by its own policy
 * verb, so the handed-out assignee never sees edit, cancel or delete (D-14
 * amendment) and a mentioned participant only comments (D-20).
 *
 * The page itself (TaskInfolist::configure()) ticks checklist items with
 * one click through `tickChecklistItem` (the policy's `tickChecklist`, then
 * TaskService), filters the thread (all · comments · progress updates) and
 * re-renders when the composer or the undo changed the task
 * (TaskActions::CHANGED_EVENT). Every assignee (D-28), the thread, its
 * authors and mentions, the checklist with who ticked it, the assigner, the
 * creator, the routine and the series are loaded with the record and again
 * before every later render (rendering()), so the page costs a fixed number
 * of queries whatever their length.
 */
final class ViewTask extends ViewRecord
{
    public const string THREAD_ALL = 'all';

    public const string THREAD_COMMENTS = 'comments';

    public const string THREAD_UPDATES = 'updates';

    /** @var list<string> */
    private const array RELATIONS = [
        // Every assignee (D-28): the properties list them, the policy checks read them.
        ...Task::ASSIGNEE_RELATIONS,
        'updates.author',
        'updates.mentions.user',
        'checklistItems.completer',
        'assigner',
        'creator',
        'template',
        'series',
    ];

    /** @var list<string> */
    private const array STATE_CHANGING_ACTIONS = [
        'statusStart', 'statusComplete', 'statusReopen', 'statusCancel',
        'start', 'complete', 'reopen', 'cancel', 'delete', 'restore',
    ];

    protected static string $resource = TaskResource::class;

    /** Which entries the thread shows: the reader's own choice, never stored. */
    public string $threadFilter = self::THREAD_ALL;

    protected function resolveRecord(int|string $key): Model
    {
        $record = parent::resolveRecord($key);

        return $record instanceof Task ? $record->loadMissing(self::RELATIONS) : $record;
    }

    /**
     * Every later request on the page (a status move, the thread filter, an
     * action's modal) restores the record from its key alone, without its
     * relations: they are loaded again before each render, so the page keeps
     * naming every assignee (D-28) — the entries never load a relation
     * themselves — and still costs a fixed number of queries. After the
     * assign action the holders are read as they stand now
     * (afterActionCalled()).
     */
    public function rendering(): void
    {
        $record = $this->getRecord();

        if ($record instanceof Task) {
            $record->loadMissing(self::RELATIONS);
        }
    }

    /** The task's title as the page heading; the tab title keeps Filament's "View …". */
    public function getHeading(): string|Htmlable
    {
        $record = $this->getRecord();

        return $record instanceof Task ? $record->title : parent::getHeading();
    }

    /** The files are drawn in the content column (TaskInfolist), not again below the page. */
    public function getRelationManagers(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            TaskActions::statusStart()
                ->button(),
            TaskActions::statusComplete()
                ->button()
                ->visible(static fn (?Model $record): bool => $record instanceof Task && $record->status === TaskStatus::InProgress && ! $record->trashed()),
            TaskActions::reopen()
                ->button(),

            ActionGroup::make([
                TaskActions::start()->label(__('tasks.page_ux.actions.start_with_note')),
                TaskActions::complete()->label(__('tasks.page_ux.actions.complete_with_note')),
                TaskActions::postUpdate(),
                TaskActions::checklist(),
                TaskActions::comment(),
                TaskActions::cancel(),
                OwnershipActions::assign(Task::permissionGroup()),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
                ->label(__('tasks.page_ux.actions.more'))
                ->icon(Heroicon::EllipsisVertical)
                ->color('gray')
                ->button(),
        ];
    }

    /**
     * Ticks or unticks one checklist item in one click (D-25): only for
     * whoever may report on the open task (`tickChecklist` — a mentioned
     * participant never, a closed or deleted task never); TaskService locks
     * the task, touches only its own items and records the tick. A refused
     * tick says why; the re-render puts the box back as it is.
     */
    public function tickChecklistItemAction(): Action
    {
        return Action::make('tickChecklistItem')
            ->authorize(fn (): bool => $this->reader()?->can('tickChecklist', $this->getRecord()) ?? false)
            ->authorizationNotification()
            ->authorizationMessage(fn (): string => $this->taskRecord()->isOpen() && ! $this->taskRecord()->trashed()
                ? __('tasks.page_ux.notifications.checklist_refused')
                : __('tasks.validation.checklist_not_open'))
            ->action(function (array $arguments): void {
                $task = $this->taskRecord();
                $item = $arguments['item'] ?? null;
                $item = is_int($item) || (is_string($item) && ctype_digit($item)) ? (int) $item : 0;
                $done = filter_var($arguments['done'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $reader = $this->reader();

                if ($item <= 0 || ! $reader instanceof User) {
                    return;
                }

                try {
                    app(TaskService::class)->tickChecklist($task, $reader, $done ? [$item] : [], $done ? [] : [$item]);
                } catch (InvalidTaskTransitionException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                }

                $task->loadMissing(self::RELATIONS);
            });
    }

    /** Shows all entries, the comments or the progress updates; anything else shows all. */
    public function setThreadFilter(mixed $filter): void
    {
        $this->threadFilter = self::threadFilterFrom($filter);
    }

    /** A filter set directly by the browser is held to the same three values. */
    public function updatedThreadFilter(mixed $value): void
    {
        $this->threadFilter = self::threadFilterFrom($value);
    }

    /**
     * A move of the task's status (or its deletion) opens or closes the
     * composer's progress mode: the composer is told to look again. The
     * assign action changed who holds the task and who handed it out (D-14,
     * D-28) under any relation already loaded: they are dropped, and
     * rendering() reads them again.
     */
    protected function afterActionCalled(Action $action): void
    {
        if ($action->getName() === 'assign') {
            $task = $this->taskRecord();

            foreach ([...Task::ASSIGNEE_RELATIONS, 'assigner'] as $relation) {
                $task->unsetRelation($relation);
            }
        }

        if (in_array($action->getName(), self::STATE_CHANGING_ACTIONS, true)) {
            $this->dispatch(TaskActions::CHANGED_EVENT, task: (int) $this->taskRecord()->getKey())->to(TaskComposer::class);
        }
    }

    /** The composer posted or a toast's «تراجع» reopened the task: read it again. */
    #[On(TaskActions::CHANGED_EVENT)]
    public function refreshTask(): void
    {
        $this->taskRecord()->refresh()->load(self::RELATIONS);
    }

    private static function threadFilterFrom(mixed $value): string
    {
        return in_array($value, [self::THREAD_ALL, self::THREAD_COMMENTS, self::THREAD_UPDATES], true) ? $value : self::THREAD_ALL;
    }

    private function taskRecord(): Task
    {
        $record = $this->getRecord();
        assert($record instanceof Task);

        return $record;
    }

    private function reader(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
