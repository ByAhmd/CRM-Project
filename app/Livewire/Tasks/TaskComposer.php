<?php

declare(strict_types=1);

namespace App\Livewire\Tasks;

use App\Exceptions\Tasks\InvalidTaskTransitionException;
use App\Filament\Support\TaskActions;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\CommentDraft;
use App\Services\Tasks\TaskService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The always-visible composer at the top of a task's thread (decision
 * D-27), on the task page of the Tasks workspace.
 *
 * Two modes, each with its own rule and its own service call, so the two
 * kinds of thread entry stay distinct:
 *
 * - a comment (D-17) for everyone who may open the task, until it is
 *   deleted — the D-20 editor (TaskActions::composerEditor(): "@" lists the
 *   colleagues TaskService::mentionableUsers() allows, the document becomes
 *   plain text and mentioned ids through CommentDraft) into
 *   TaskService::comment();
 * - a progress update («تحديث إنجاز», D-14 amendment) only for those who may
 *   report on the task while it is open (`postUpdate`, never a mentioned
 *   participant) into TaskService::postUpdate().
 *
 * The task id is locked and the task is looked up again on every request;
 * each post asks the policy again, whatever the screen showed. The draft is
 * the writer's own: synced while they type (debounced) and kept in their
 * session under their user id and the task's id — never shared, never in
 * the browser's storage — so leaving the page and coming back finds it; a
 * post clears it. After a post the page is told to re-render its thread
 * (TaskActions::CHANGED_EVENT).
 *
 * @property-read Schema $form
 */
final class TaskComposer extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public const string MODE_COMMENT = 'comment';

    public const string MODE_PROGRESS = 'progress';

    /** How long the editor waits after the last keystroke before the draft is synced. */
    public const int DRAFT_DEBOUNCE_MS = 1000;

    #[Locked]
    public int $taskId = 0;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    private ?Task $resolved = null;

    public function mount(?Model $record = null): void
    {
        abort_unless($record instanceof Task, 404);

        $this->taskId = (int) $record->getKey();

        $this->task();

        $draft = session()->get($this->draftKey());

        $this->form->fill(is_array($draft) ? $this->draftState($draft) : ['mode' => self::MODE_COMMENT]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->record($this->task())
            ->statePath('data')
            ->columns(1)
            ->components([
                ToggleButtons::make('mode')
                    ->label(__('tasks.page_ux.composer.mode'))
                    ->options([
                        self::MODE_COMMENT => __('tasks.page_ux.composer.comment'),
                        self::MODE_PROGRESS => __('tasks.page_ux.composer.progress'),
                    ])
                    ->icons([
                        self::MODE_COMMENT => Heroicon::OutlinedChatBubbleLeftRight,
                        self::MODE_PROGRESS => Heroicon::OutlinedChatBubbleLeftEllipsis,
                    ])
                    ->inline()
                    ->grouped()
                    ->live()
                    ->disableOptionWhen(fn (string $value): bool => $value === self::MODE_PROGRESS && ! $this->mayReport())
                    ->visible(fn (): bool => $this->mayReport() || $this->stranded()),

                TaskActions::composerEditor()
                    ->live(debounce: self::DRAFT_DEBOUNCE_MS)
                    ->visible(fn (): bool => $this->currentMode() === self::MODE_COMMENT),

                Textarea::make('body')
                    ->label(__('tasks.fields.update_body'))
                    ->placeholder(__('tasks.page_ux.composer.progress_placeholder'))
                    ->helperText(static fn (?Model $record): ?string => TaskActions::progressNoticeHelper($record))
                    ->rows(3)
                    ->autosize()
                    ->required()
                    ->maxLength(TaskService::PROGRESS_TEXT_MAX)
                    ->live(debounce: self::DRAFT_DEBOUNCE_MS)
                    ->disabled(fn (): bool => ! $this->mayReport())
                    ->visible(fn (): bool => $this->currentMode() === self::MODE_PROGRESS),
            ]);
    }

    /**
     * Posts the entry the mode names: a comment for whoever may comment, a
     * progress update for whoever may report — the action's authorisation
     * and the service both decide again; the form only lets it through.
     */
    public function postAction(): Action
    {
        return Action::make('post')
            ->label(fn (): string => $this->currentMode() === self::MODE_PROGRESS ? __('tasks.actions.post_update_submit') : __('tasks.actions.comment_submit'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->extraAttributes(['aria-keyshortcuts' => 'Control+Enter'])
            ->authorize(fn (): bool => $this->mayComment() || $this->mayReport())
            ->action(function (): void {
                $mode = $this->currentMode();

                // The task closed, or the right to report was withdrawn, while the
                // update was being written: say so and keep the text where it is —
                // never post it, never turn it into something else.
                if ($mode === self::MODE_PROGRESS && ! $this->mayReport()) {
                    Notification::make()->title($this->progressRefusal())->danger()->send();

                    return;
                }

                if ($mode === self::MODE_COMMENT && ! $this->mayComment()) {
                    Notification::make()->title(InvalidTaskTransitionException::commentOnTrashed()->getMessage())->danger()->send();

                    return;
                }

                $state = $this->form->getState();
                $task = $this->task();
                $actor = $this->actor();

                try {
                    if ($mode === self::MODE_PROGRESS) {
                        app(TaskService::class)->postUpdate($task, $actor, (string) ($state['body'] ?? ''));
                        $title = __('tasks.notifications.update_posted');
                    } else {
                        $draft = CommentDraft::fromDocument($state['comment'] ?? null);
                        app(TaskService::class)->comment($task, $actor, $draft->text, $draft->mentionedUserIds);
                        $title = __('tasks.notifications.comment_posted');
                    }
                } catch (InvalidTaskTransitionException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                } catch (ValidationException $exception) {
                    Notification::make()->title((string) Arr::first(Arr::flatten($exception->errors())))->danger()->send();

                    return;
                }

                session()->forget($this->draftKey());
                $this->form->fill(['mode' => $mode]);

                Notification::make()->title($title)->success()->send();

                $this->dispatch(TaskActions::CHANGED_EVENT, task: $this->taskId);
            });
    }

    /** Keeps the writer's draft in their session as they type. */
    public function updated(string $name): void
    {
        if ($name !== 'data' && ! str_starts_with($name, 'data.')) {
            return;
        }

        session()->put($this->draftKey(), [
            'mode' => $this->mode($this->data['mode'] ?? null),
            'comment' => $this->data['comment'] ?? null,
            'body' => is_string($this->data['body'] ?? null) ? $this->data['body'] : null,
        ]);
    }

    /** A change made elsewhere (a status move, the undo) may open or close the progress mode. */
    #[On(TaskActions::CHANGED_EVENT)]
    public function refreshComposer(): void
    {
        $this->resolved = null;
    }

    public function render(): View
    {
        return view('livewire.tasks.task-composer', [
            'mayPost' => $this->mayComment() || $this->mayReport(),
        ]);
    }

    public function mayComment(): bool
    {
        return $this->actor()->can('comment', $this->task());
    }

    /** Progress updates: whoever may report on the task, while it is open and not deleted. */
    public function mayReport(): bool
    {
        $task = $this->task();

        return $task->isOpen() && ! $task->trashed() && $this->actor()->can('postUpdate', $task);
    }

    /**
     * The mode in force: progress for whoever may report now, and — so a
     * written update is never hidden — for a progress text that can no
     * longer be posted (stranded()); a comment otherwise.
     */
    public function currentMode(): string
    {
        return $this->stranded() ? self::MODE_PROGRESS : $this->effectiveMode($this->data['mode'] ?? null);
    }

    /**
     * A progress update written while it could be posted, which no longer
     * can be (the task closed, or the reader lost the right to report): the
     * box stays on screen, read-only, so the text can be copied, and the
     * mode switch offers the comment instead.
     */
    private function stranded(): bool
    {
        return $this->mode($this->data['mode'] ?? null) === self::MODE_PROGRESS
            && is_string($this->data['body'] ?? null) && trim($this->data['body']) !== ''
            && ! $this->mayReport();
    }

    /** Why a progress update cannot be posted now. */
    private function progressRefusal(): string
    {
        $task = $this->task();

        return $task->isOpen() && ! $task->trashed()
            ? __('tasks.validation.update_not_allowed')
            : InvalidTaskTransitionException::notOpenForUpdate()->getMessage();
    }

    /** A chosen mode as it may be used: progress falls back to a comment for whoever may not report. */
    private function effectiveMode(mixed $value): string
    {
        $mode = $this->mode($value);

        return $mode === self::MODE_PROGRESS && ! $this->mayReport() ? self::MODE_COMMENT : $mode;
    }

    /**
     * The task, found again on every request (deleted ones too, so a deleted
     * task's page shows no composer rather than an error) and only while the
     * reader may still open it.
     */
    private function task(): Task
    {
        if ($this->resolved instanceof Task) {
            return $this->resolved;
        }

        $task = Task::query()->withTrashed()->find($this->taskId);

        abort_unless($task instanceof Task, 404);
        abort_unless($this->actor()->can('view', $task), 403);

        return $this->resolved = $task;
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function mode(mixed $value): string
    {
        return $value === self::MODE_PROGRESS ? self::MODE_PROGRESS : self::MODE_COMMENT;
    }

    /** One draft per writer and task, in the writer's own session. */
    private function draftKey(): string
    {
        return 'crm.task_composer.'.$this->actor()->getKey().'.'.$this->taskId;
    }

    /**
     * A stored draft as form state, every value checked for its shape.
     *
     * @param  array<mixed>  $draft
     * @return array<string, mixed>
     */
    private function draftState(array $draft): array
    {
        $mode = $draft['mode'] ?? null;

        return [
            // The chosen mode as stored: a progress text that can no longer be posted stays visible (stranded()).
            'mode' => $this->mode($mode),
            'comment' => is_array($draft['comment'] ?? null) || is_string($draft['comment'] ?? null) ? $draft['comment'] : null,
            'body' => is_string($draft['body'] ?? null) ? $draft['body'] : null,
        ];
    }
}
