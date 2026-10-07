<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\DueShortcut;
use App\Enums\Permission;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskTransition;
use App\Exceptions\Access\UnassignableUserException;
use App\Exceptions\Tasks\InvalidTaskTransitionException;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Settings\OrganisationCalendar;
use App\Services\Tasks\CommentDraft;
use App\Services\Tasks\DueShortcuts;
use App\Services\Tasks\TaskChecklist;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\MentionProvider;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;

/**
 * The task status actions (decisions A-10, D-14), shared by the table, the
 * view page and the relation managers: start and post update (the
 * assignee's progress reports, D-14 amendment 2026-09-28), comment (anyone
 * who may view the task, D-17, mentioning colleagues with "@", D-20),
 * checklist (ticking the task's steps, D-25), complete (with an optional
 * note, and a warning when checklist items are left unticked), cancel,
 * reopen and the bulk complete.
 *
 * Each action authorises through the policy verb and delegates to
 * TaskService, which owns every rule; a refused transition surfaces as a
 * danger notification. Every closure tolerates a missing record so the
 * actions can be declared before Filament resolves one.
 *
 * Two families live here (D-27). The actions above the "Tasks workspace"
 * marker below are shared with the Sales task tabs (BaseTasksRelationManager)
 * and keep their dialogs and behaviour; the quick controls below it — the
 * status moves and menus, the one-click complete with its «تراجع» undo, the
 * reschedule chips and quick add — serve the Tasks-workspace screens only.
 * Every one of them is authorised by the same TaskPolicy verbs and writes
 * through TaskService alone: they make the allowed actions quicker, never
 * wider (D-14 and its amendment, D-17, D-20, D-25 unchanged).
 */
final class TaskActions
{
    /** The most colleagues the "@" list offers at once; typing narrows it. */
    public const int MENTION_RESULTS = 8;

    /**
     * The Livewire event the «تراجع» button of a completion toast dispatches
     * with `task` (the id); App\Livewire\TaskUndo, mounted on every page of
     * the Tasks workspace, reopens the task through TaskService after the
     * policy's `reopen` answer.
     */
    public const string UNDO_COMPLETE_EVENT = 'crm-task-undo-complete';

    /**
     * Dispatched (with `task`) after a task changed outside the component
     * that shows it — the undo. A screen that lists tasks listens with
     * #[On(TaskActions::CHANGED_EVENT)] to re-render.
     */
    public const string CHANGED_EVENT = 'crm-tasks-changed';

    /** The create page's query parameter holding quick add's one-time prefill token (never the values). */
    public const string PREFILL_PARAMETER = 'prefill';

    /** Where «خيارات أكثر» keeps the quick-add values for the create page, in the reader's server session. */
    public const string PREFILL_SESSION_KEY = 'crm_tasks_quick_add_prefill';

    /**
     * Pending → In progress, with an optional note (D-14 amendment,
     * 2026-09-28); the assigner is told.
     */
    public static function start(): Action
    {
        return Action::make('start')
            ->label(__('tasks.actions.start'))
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('info')
            ->modalHeading(__('tasks.actions.start_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.start_submit'))
            ->schema([
                Section::make(__('tasks.sections.progress'))
                    ->columns(1)
                    ->schema([
                        Textarea::make('start_note')
                            ->label(__('tasks.fields.start_note'))
                            ->placeholder(__('tasks.placeholders.start_note'))
                            ->helperText(fn (?Model $record): ?string => self::reportNoticeHelper($record))
                            ->rows(3)
                            ->maxLength(TaskService::PROGRESS_TEXT_MAX),
                    ]),
            ])
            ->visible(fn (?Model $record): bool => $record instanceof Task && $record->status === TaskStatus::Pending && ! $record->trashed())
            ->authorize(fn (?Model $record): bool => $record instanceof Task && self::can('start', $record))
            ->action(function (Task $record, array $data): void {
                self::attempt(function () use ($record, $data): void {
                    app(TaskService::class)->start($record, self::actor(), (string) ($data['start_note'] ?? ''));

                    Notification::make()->title(__('tasks.notifications.started'))->success()->send();
                });
            });
    }

    /**
     * A progress note on an open task (D-14 amendment, 2026-09-28): required,
     * at most TaskService::PROGRESS_TEXT_MAX characters; the assigner is told.
     */
    public static function postUpdate(): Action
    {
        return Action::make('postUpdate')
            ->label(__('tasks.actions.post_update'))
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color('gray')
            ->modalHeading(__('tasks.actions.post_update_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.post_update_submit'))
            ->schema([
                Section::make(__('tasks.sections.progress'))
                    ->columns(1)
                    ->schema([
                        Textarea::make('body')
                            ->label(__('tasks.fields.update_body'))
                            ->placeholder(__('tasks.placeholders.update_body'))
                            ->helperText(fn (?Model $record): ?string => self::reportNoticeHelper($record))
                            ->required()
                            ->rows(4)
                            ->maxLength(TaskService::PROGRESS_TEXT_MAX),
                    ]),
            ])
            ->visible(fn (?Model $record): bool => $record instanceof Task && $record->isOpen() && ! $record->trashed())
            ->authorize(fn (?Model $record): bool => $record instanceof Task && self::can('postUpdate', $record))
            ->action(function (Task $record, array $data): void {
                self::attempt(function () use ($record, $data): void {
                    app(TaskService::class)->postUpdate($record, self::actor(), (string) ($data['body'] ?? ''));

                    Notification::make()->title(__('tasks.notifications.update_posted'))->success()->send();
                });
            });
    }

    /**
     * A comment on the task (D-17) from anyone who may view it, on an open or
     * a closed task but not a deleted one: required, at most
     * TaskService::COMMENT_TEXT_MAX characters; the participants are told.
     * Typing "@" lists the colleagues who may be mentioned (D-20); the
     * editor's document becomes plain text and mentioned ids through
     * CommentDraft before the service sees it.
     */
    public static function comment(): Action
    {
        return Action::make('comment')
            ->label(__('tasks.actions.comment'))
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->color('gray')
            ->modalHeading(__('tasks.actions.comment_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.comment_submit'))
            ->schema([
                Section::make(__('tasks.sections.comment'))
                    ->columns(1)
                    ->schema([
                        self::commentEditor(),
                    ]),
            ])
            ->visible(fn (?Model $record): bool => $record instanceof Task && ! $record->trashed())
            ->authorize(fn (?Model $record): bool => $record instanceof Task && self::can('comment', $record))
            ->action(function (Task $record, array $data): void {
                self::attempt(function () use ($record, $data): void {
                    $draft = CommentDraft::fromDocument($data['comment'] ?? null);

                    app(TaskService::class)->comment($record, self::actor(), $draft->text, $draft->mentionedUserIds);

                    Notification::make()->title(__('tasks.notifications.comment_posted'))->success()->send();
                });
            });
    }

    /**
     * The comment box (D-17, D-20): a rich editor without a single formatting
     * button or file upload — the comment stays plain text — whose one job is
     * the "@" list of colleagues TaskService::mentionableUsers() allows: the
     * first MENTION_RESULTS by name as soon as "@" is typed, then searched by
     * name as the user types on. Its labels come from the same query, so a
     * stale or forged id shows no name. The required and length rules judge
     * the plain text CommentDraft makes of the document — the very text the
     * service stores — not the document's own size.
     */
    private static function commentEditor(): RichEditor
    {
        return RichEditor::make('comment')
            ->label(__('tasks.fields.comment_body'))
            ->placeholder(__('tasks.placeholders.comment_body'))
            ->hint(__('tasks.helpers.mention_hint'))
            ->hintIcon(Heroicon::OutlinedAtSymbol)
            ->helperText(fn (?Model $record): ?string => self::commentNoticeHelper($record))
            ->json()
            ->toolbarButtons([])
            ->fileAttachments(false)
            ->mentions([
                MentionProvider::make('@')
                    // Typing "@" alone lists the first colleagues by name (D-20); a letter more searches them all.
                    ->items(static fn (): array => self::mentionableUsers()
                        ->limit(self::MENTION_RESULTS)
                        ->pluck('name', 'id')
                        ->all())
                    ->getSearchResultsUsing(static fn (string $search): array => self::mentionableUsers()
                        ->where('name', 'like', "%{$search}%")
                        ->limit(self::MENTION_RESULTS)
                        ->pluck('name', 'id')
                        ->all())
                    ->getLabelsUsing(static fn (array $ids): array => self::mentionableUsers()
                        ->whereKey(self::userIds($ids))
                        ->pluck('name', 'id')
                        ->all())
                    ->noSearchResultsMessage(__('tasks.empty.mention_results')),
            ])
            ->required()
            ->mutateStateForValidationUsing(static fn (RichEditor $component, mixed $state): string => CommentDraft::fromDocument(self::commentDocument($component, $state))->text)
            ->rules(['required', 'string', 'max:'.TaskService::COMMENT_TEXT_MAX]);
    }

    /**
     * The colleagues the signed-in user may mention.
     *
     * @return Builder<User>
     */
    private static function mentionableUsers(): Builder
    {
        return app(TaskService::class)->mentionableUsers(self::actor());
    }

    /**
     * The well-formed user ids among those the browser asked labels for;
     * anything else it sent is ignored.
     *
     * @param  array<mixed>  $ids
     * @return list<int>
     */
    private static function userIds(array $ids): array
    {
        $valid = [];

        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $valid[] = (int) $id;
            }
        }

        return $valid;
    }

    /**
     * The editor state as a TipTap document, read through the editor's own
     * schema exactly as the field's state cast reads it for the action, so
     * validation judges the same document the action receives. The browser
     * always sends the document; a scripted request may send a string, which
     * the editor reads as HTML — or as JSON, and a bare JSON scalar is no
     * document at all.
     *
     * @return array<mixed>|null
     */
    private static function commentDocument(RichEditor $editor, mixed $state): ?array
    {
        if (is_string($state)) {
            $decoded = json_decode($state, true);

            if (json_last_error() === JSON_ERROR_NONE && ! is_array($decoded)) {
                return null;
            }
        } elseif (! is_array($state)) {
            return null;
        }

        $document = $editor->getTipTapEditor()->setContent($state)->getDocument();

        return is_array($document) ? $document : null;
    }

    /**
     * Ticks the checklist of an open task (D-25): one box per item, the
     * ticked ones checked. Whoever may report on the task may tick — the
     * handed-out assignee included, a mentioned participant never — and only
     * the items whose box changed since the dialog opened are written, so two
     * people ticking different items at once do not undo each other.
     */
    public static function checklist(): Action
    {
        return Action::make('checklist')
            ->label(__('tasks.actions.checklist'))
            ->icon(Heroicon::OutlinedListBullet)
            ->color('gray')
            ->modalHeading(__('tasks.actions.checklist_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.checklist_submit'))
            ->fillForm(static function (?Model $record): array {
                $done = $record instanceof Task ? self::doneItemIds($record) : [];

                return ['done' => $done, 'shown_done' => $done];
            })
            ->schema([
                Section::make(__('tasks.sections.checklist'))
                    ->columns(1)
                    ->schema([
                        CheckboxList::make('done')
                            ->label(__('tasks.fields.checklist_done_items'))
                            ->helperText(__('tasks.helpers.checklist_tick'))
                            ->options(static fn (?Model $record): array => $record instanceof Task ? self::itemOptions($record) : []),

                        Hidden::make('shown_done'),
                    ]),
            ])
            ->visible(fn (?Model $record): bool => $record instanceof Task && $record->isOpen() && ! $record->trashed() && TaskChecklist::counts($record)['total'] > 0)
            ->authorize(fn (?Model $record): bool => $record instanceof Task && self::can('tickChecklist', $record))
            ->action(function (Task $record, array $data): void {
                self::attempt(function () use ($record, $data): void {
                    $done = self::idList($data['done'] ?? []);
                    $shown = self::idList($data['shown_done'] ?? []);

                    app(TaskService::class)->tickChecklist(
                        $record,
                        self::actor(),
                        array_values(array_diff($done, $shown)),
                        array_values(array_diff($shown, $done)),
                    );

                    Notification::make()->title(__('tasks.notifications.checklist_saved'))->success()->send();
                });
            });
    }

    public static function complete(): Action
    {
        return Action::make('complete')
            ->label(__('tasks.actions.complete'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading(__('tasks.actions.complete_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.complete_submit'))
            ->schema([
                // Completing with unticked items is allowed, with a warning (D-25).
                Callout::make(static fn (?Model $record): ?string => $record instanceof Task ? self::openItemsWarning($record) : null)
                    ->key('checklistWarning')
                    ->description(__('tasks.helpers.checklist_complete_anyway'))
                    ->warning()
                    ->visible(static fn (?Model $record): bool => $record instanceof Task && self::openItemsWarning($record) !== null),

                Section::make(__('tasks.sections.notes'))
                    ->columns(1)
                    ->schema([
                        Textarea::make('completion_note')
                            ->label(__('tasks.fields.completion_note'))
                            ->rows(3)
                            ->maxLength(2000),
                    ]),
            ])
            ->visible(fn (?Model $record): bool => $record instanceof Task && $record->isOpen() && ! $record->trashed())
            ->authorize(fn (?Model $record): bool => $record instanceof Task && self::can('complete', $record))
            ->action(function (Task $record, array $data): void {
                self::attempt(function () use ($record, $data): void {
                    app(TaskService::class)->complete($record, self::actor(), (string) ($data['completion_note'] ?? ''));

                    Notification::make()->title(__('tasks.notifications.completed'))->success()->send();
                });
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label(__('tasks.actions.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('tasks.actions.cancel_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.cancel_submit'))
            ->visible(fn (?Model $record): bool => $record instanceof Task && $record->isOpen() && ! $record->trashed())
            ->authorize(fn (?Model $record): bool => $record instanceof Task && self::can('cancel', $record))
            ->action(function (Task $record): void {
                self::attempt(function () use ($record): void {
                    app(TaskService::class)->cancel($record, self::actor());

                    Notification::make()->title(__('tasks.notifications.cancelled'))->success()->send();
                });
            });
    }

    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->label(__('tasks.actions.reopen'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('tasks.actions.reopen_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.reopen_submit'))
            ->visible(fn (?Model $record): bool => $record instanceof Task && $record->isClosed() && ! $record->trashed())
            ->authorize(fn (?Model $record): bool => $record instanceof Task && self::can('reopen', $record))
            ->action(function (Task $record): void {
                self::attempt(function () use ($record): void {
                    app(TaskService::class)->reopen($record, self::actor());

                    Notification::make()->title(__('tasks.notifications.reopened'))->success()->send();
                });
            });
    }

    /**
     * Completes every selected open task the actor may complete; closed and
     * trashed ones are skipped rather than refused, so a mixed selection
     * still finishes. When the open tasks of the selection carry unticked
     * checklist items, the dialog says how many (D-25).
     */
    public static function completeBulk(): BulkAction
    {
        return BulkAction::make('complete')
            ->label(__('tasks.actions.complete'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(static fn (mixed $records): ?string => is_iterable($records) ? self::openItemsWarningAcross($records) : null)
            ->modalHeading(__('tasks.actions.complete_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.complete_submit'))
            ->authorizeIndividualRecords('complete')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $service = app(TaskService::class);
                $actor = self::actor();
                $count = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Task || ! $record->isOpen() || $record->trashed()) {
                        continue;
                    }

                    $service->complete($record, $actor);
                    $count++;
                }

                Notification::make()->title(trans_choice('tasks.notifications.bulk_completed', $count, ['count' => $count]))->success()->send();
            });
    }

    /* ---------------------------------------------------------------------
     | Tasks workspace (D-27): quick controls for the Tasks-workspace screens.
     | The Sales task tabs keep the actions above.
     * ------------------------------------------------------------------- */

    /**
     * The moves the signed-in user may make on the task now (start,
     * complete, reopen, cancel) — the workflow's shape and the policy
     * together (TaskTransition::allowedFor()).
     *
     * @return list<TaskTransition>
     */
    public static function transitionsFor(Task $task): array
    {
        $user = auth()->user();

        return $user instanceof User ? TaskTransition::allowedFor($task, $user) : [];
    }

    /**
     * Whether the signed-in user may complete the task now — what
     * quickComplete() and statusComplete() show and authorise — so a
     * screen draws the completion check as a button only then.
     */
    public static function mayComplete(Task $task): bool
    {
        return TaskTransition::Complete->appliesTo($task) && self::can(TaskTransition::Complete->ability(), $task);
    }

    /**
     * Whether the signed-in user may move the task's due date — what the
     * reschedule actions show and authorise: an open task and `update`
     * (the dates stay with the assigner, D-14).
     */
    public static function mayReschedule(Task $task): bool
    {
        return $task->isOpen() && ! $task->trashed() && self::can('update', $task);
    }

    /**
     * Pending → In progress in one click, without the note dialog the
     * shared Start action offers; the assigner is told as for any start.
     */
    public static function statusStart(): Action
    {
        return self::transitionAction('statusStart', TaskTransition::Start)
            ->action(function (Task $record): void {
                self::attempt(function () use ($record): void {
                    app(TaskService::class)->start($record, self::actor());

                    Notification::make()->title(__('tasks.notifications.started'))->success()->send();
                });
            });
    }

    /**
     * Completes in one click (status menu). No dialog — unless checklist
     * items are still open, when the D-25 warning dialog (with the optional
     * note) comes first. The success toast offers «تراجع», which reopens the
     * task for whoever may reopen it (TaskUndo).
     */
    public static function statusComplete(): Action
    {
        return self::oneClickComplete('statusComplete')
            ->label(TaskTransition::Complete->getLabel())
            ->color(TaskTransition::Complete->getColor());
    }

    /**
     * The completion check of a task row or card: statusComplete() under its
     * own name, labelled for its icon-only look.
     */
    public static function quickComplete(): Action
    {
        return self::oneClickComplete('quickComplete')
            ->label(__('tasks.actions.quick_complete'))
            ->color('gray');
    }

    /**
     * Reopens a closed task in one click: a completed task for whoever may
     * report on it, a cancelled one only for those who may edit it.
     */
    public static function statusReopen(): Action
    {
        return self::transitionAction('statusReopen', TaskTransition::Reopen)
            ->action(function (Task $record): void {
                self::attempt(function () use ($record): void {
                    app(TaskService::class)->reopen($record, self::actor());

                    Notification::make()->title(__('tasks.notifications.reopened'))->success()->send();
                });
            });
    }

    /**
     * Cancels an open task, after a confirmation: cancelling belongs to
     * those who may edit it (`cancel` = `update`), never to the handed-out
     * assignee.
     */
    public static function statusCancel(): Action
    {
        return self::transitionAction('statusCancel', TaskTransition::Cancel)
            ->requiresConfirmation()
            ->modalHeading(__('tasks.actions.cancel_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.cancel_submit'))
            ->action(function (Task $record): void {
                self::attempt(function () use ($record): void {
                    app(TaskService::class)->cancel($record, self::actor());

                    Notification::make()->title(__('tasks.notifications.cancelled'))->success()->send();
                });
            });
    }

    /**
     * The status pill that changes status in place: a badge in the task's
     * status colour and icon, opening the moves the reader may make —
     * statusStart, statusComplete, statusReopen, statusCancel. A reader who
     * may make none sees no menu at all (Filament hides a group whose
     * actions are all refused); show TaskVisuals::statusPill() there.
     * For places that take one action and not a group (a table cell's
     * action), use changeStatus().
     */
    public static function statusMenu(): ActionGroup
    {
        return ActionGroup::make([
            self::statusStart(),
            self::statusComplete(),
            self::statusReopen(),
            self::statusCancel(),
        ])
            ->label(static fn (?Model $record): string => $record instanceof Task ? $record->status->getLabel() : __('tasks.fields.status'))
            ->icon(static fn (?Model $record): ?Heroicon => $record instanceof Task ? TaskVisuals::statusIcon($record->status) : null)
            ->color(static fn (?Model $record): string => $record instanceof Task ? $record->status->getColor() : 'gray')
            ->tooltip(__('tasks.actions.change_status'))
            ->badge();
    }

    /**
     * The status pill as one action (a table's status cell takes a single
     * action, not a menu): a small dialog saying the current status whose
     * buttons are exactly the moves the reader may make — the same four
     * actions statusMenu() holds, each with its own authorisation, so a
     * completion still shows the D-25 warning first. Hidden when the reader
     * may make no move.
     */
    public static function changeStatus(): Action
    {
        return Action::make('changeStatus')
            ->label(__('tasks.actions.change_status'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->modalHeading(__('tasks.actions.change_status_heading'))
            ->modalDescription(static fn (?Model $record): ?string => $record instanceof Task ? __('tasks.helpers.change_status', ['status' => $record->status->getLabel()]) : null)
            ->modalSubmitAction(false)
            ->extraModalFooterActions([
                self::statusStart()->cancelParentActions(),
                self::statusComplete()->cancelParentActions(),
                self::statusReopen()->cancelParentActions(),
                self::statusCancel()->cancelParentActions(),
            ])
            ->visible(static fn (?Model $record): bool => $record instanceof Task && self::transitionsFor($record) !== [])
            ->authorize(static fn (?Model $record): bool => $record instanceof Task && self::transitionsFor($record) !== []);
    }

    /**
     * Moves an open task's due date to the chip's day in one click — Today,
     * Tomorrow or Next week on the organisation calendar, at the task's own
     * time or 17:00 (DueShortcuts) — for those who may edit the task (the
     * handed-out assignee never: the dates stay with the assigner, D-14).
     */
    public static function rescheduleTo(DueShortcut $shortcut): Action
    {
        $name = match ($shortcut) {
            DueShortcut::Today => 'rescheduleToday',
            DueShortcut::Tomorrow => 'rescheduleTomorrow',
            DueShortcut::NextWeek => 'rescheduleNextWeek',
            DueShortcut::Pick => null,
            DueShortcut::None => throw new InvalidArgumentException('A reschedule chip moves a due date; it never clears one.'),
        };

        if ($name === null) {
            return self::reschedulePick();
        }

        return self::rescheduleAction($name, $shortcut)
            ->action(function (Task $record) use ($shortcut): void {
                self::moveDue($record, app(DueShortcuts::class)->resolve($shortcut, $record->due_at));
            });
    }

    /** "Pick a date": a date picker (an organisation date), the task keeping its time of day or 17:00. */
    public static function reschedulePick(): Action
    {
        return self::rescheduleAction('reschedulePick', DueShortcut::Pick)
            ->modalHeading(__('tasks.actions.reschedule_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.reschedule_submit'))
            ->fillForm(static fn (?Model $record): array => ['due_on' => $record instanceof Task && $record->due_at !== null ? app(OrganisationCalendar::class)->local($record->due_at)->format('Y-m-d') : null])
            ->schema([
                DatePicker::make('due_on')
                    ->label(__('tasks.fields.due_on'))
                    ->helperText(__('tasks.helpers.reschedule_time'))
                    ->native(false)
                    ->required(),
            ])
            ->action(function (Task $record, array $data): void {
                self::moveDue($record, app(DueShortcuts::class)->resolve(DueShortcut::Pick, $record->due_at, self::dateString($data['due_on'] ?? null)));
            });
    }

    /**
     * The reschedule chips as one action (a table's due cell takes a single
     * action): a small dialog with the chips — Today, Tomorrow, Next week,
     * Pick a date — and the picker when picking.
     */
    public static function reschedule(): Action
    {
        return self::rescheduleAction('reschedule', DueShortcut::Pick)
            ->label(__('tasks.actions.reschedule'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->modalHeading(__('tasks.actions.reschedule_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.reschedule_submit'))
            ->fillForm(static fn (): array => ['due_shortcut' => DueShortcut::Tomorrow->value])
            ->schema([
                ToggleButtons::make('due_shortcut')
                    ->label(__('tasks.fields.due_shortcut'))
                    ->helperText(__('tasks.helpers.reschedule_time'))
                    ->options(self::shortcutOptions(DueShortcut::rescheduleChoices()))
                    ->icons(self::shortcutIcons(DueShortcut::rescheduleChoices()))
                    ->inline()
                    ->live()
                    ->required(),

                DatePicker::make('due_on')
                    ->label(__('tasks.fields.due_on'))
                    ->native(false)
                    ->visible(static fn (Get $get): bool => self::shortcut($get('due_shortcut')) === DueShortcut::Pick)
                    ->required(static fn (Get $get): bool => self::shortcut($get('due_shortcut')) === DueShortcut::Pick),
            ])
            ->action(function (Task $record, array $data): void {
                $shortcut = self::shortcut($data['due_shortcut'] ?? null);

                if ($shortcut === null || $shortcut === DueShortcut::None) {
                    return;
                }

                self::moveDue($record, app(DueShortcuts::class)->resolve($shortcut, $record->due_at, self::dateString($data['due_on'] ?? null)));
            });
    }

    /**
     * Quick add (D-27): a small dialog — the title, the assignee (the reader;
     * a holder of `task.assign` may choose anyone in their reach, D-14), the
     * due date as chips (or a picked date) and the priority — creating
     * through TaskService::create(), which re-checks the assignee. «خيارات
     * أكثر» validates the same fields, keeps them in the reader's session and
     * opens the full create page with a one-time token only (takePrefill()
     * and createPrefill() re-validate them there). The button carries
     * data-crm-shortcut="quick-add", so the N key and the phone bar's «+»
     * open it in place on any page that offers it (tasks-shortcuts).
     */
    public static function quickAdd(): Action
    {
        return Action::make('quickAdd')
            ->label(__('tasks.actions.quick_add'))
            ->icon(Heroicon::OutlinedPlus)
            ->extraAttributes(['data-crm-shortcut' => 'quick-add'])
            ->modalHeading(__('tasks.actions.quick_add_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.add_submit'))
            ->fillForm(static fn (): array => [
                'assignee_id' => auth()->id(),
                'due_shortcut' => DueShortcut::None->value,
                'priority' => TaskPriority::Medium->value,
            ])
            ->schema([
                TextInput::make('title')
                    ->label(__('tasks.fields.title'))
                    ->placeholder(__('tasks.placeholders.title'))
                    ->required()
                    ->maxLength(200)
                    ->autofocus(),

                Select::make('assignee_id')
                    ->label(__('tasks.fields.assignee'))
                    ->helperText(__('tasks.helpers.quick_add_assignee'))
                    ->options(static fn (): array => self::quickAddAssignees())
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->visible(static fn (): bool => self::canAssign()),

                ToggleButtons::make('due_shortcut')
                    ->label(__('tasks.fields.due_shortcut'))
                    ->options(self::shortcutOptions(DueShortcut::cases()))
                    ->icons(self::shortcutIcons(DueShortcut::cases()))
                    ->inline()
                    ->live()
                    ->required(),

                DatePicker::make('due_on')
                    ->label(__('tasks.fields.due_on'))
                    ->native(false)
                    ->visible(static fn (Get $get): bool => self::shortcut($get('due_shortcut')) === DueShortcut::Pick)
                    ->required(static fn (Get $get): bool => self::shortcut($get('due_shortcut')) === DueShortcut::Pick),

                ToggleButtons::make('priority')
                    ->label(__('tasks.fields.priority'))
                    ->options(TaskPriority::class)
                    ->inline()
                    ->required(),
            ])
            ->extraModalFooterActions(static fn (Action $action): array => [
                $action->makeModalSubmitAction('moreOptions', arguments: ['more' => true])
                    ->label(__('tasks.actions.more_options'))
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal),
            ])
            ->authorize(static fn (): bool => auth()->user()?->can('create', Task::class) ?? false)
            ->action(function (array $data, array $arguments, Component $livewire): void {
                $actor = self::actor();
                $attributes = self::quickAddAttributes($data, $actor);

                if ($arguments['more'] ?? false) {
                    $livewire->redirect(TaskResource::getUrl('create', [self::PREFILL_PARAMETER => self::stashPrefill($attributes)]));

                    return;
                }

                try {
                    TaskForm::saving(static fn (): Task => app(TaskService::class)->create($attributes, $actor));
                } catch (UnassignableUserException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    throw new Halt;
                }

                Notification::make()->title(__('tasks.notifications.added'))->success()->send();
            });
    }

    /**
     * The create page's prefill for the token in its address: the values quick
     * add's «خيارات أكثر» kept in this reader's session (stashPrefill()),
     * taken once — the slot is forgotten whether the token matches or not —
     * and validated by createPrefill(). An unknown, stale or forged token
     * gives an empty prefill.
     *
     * @return array<string, mixed>
     */
    public static function takePrefill(mixed $token, User $actor): array
    {
        if (! is_string($token) || $token === '') {
            return [];
        }

        $stash = session()->pull(self::PREFILL_SESSION_KEY);

        if (! is_array($stash) || ! is_string($stash['token'] ?? null) || ! hash_equals($stash['token'], $token) || ! is_array($stash['values'] ?? null)) {
            return [];
        }

        return self::createPrefill($stash['values'], $actor);
    }

    /**
     * The full create form's state from the values quick add's «خيارات
     * أكثر» kept (`title`, `assignee`, `due` as "Y-m-d H:i" on the
     * organisation clock, `priority`) — each value validated and dropped
     * when it is not what the form would accept: a title of at most 200
     * characters, a known priority, a real moment, and an assignee only for
     * a holder of `task.assign` and only within their reach. The create page
     * fills these over its defaults; TaskService checks again on save.
     *
     * @param  array<mixed>  $query
     * @return array<string, mixed>
     */
    public static function createPrefill(array $query, User $actor): array
    {
        $prefill = [];

        $title = is_string($query['title'] ?? null) ? trim($query['title']) : '';

        if ($title !== '' && mb_strlen($title) <= 200) {
            $prefill['title'] = $title;
        }

        $priority = is_string($query['priority'] ?? null) ? TaskPriority::tryFrom($query['priority']) : null;

        if ($priority !== null) {
            $prefill['priority'] = $priority->value;
        }

        $due = is_string($query['due'] ?? null) ? self::organisationMoment($query['due']) : null;

        if ($due !== null) {
            $prefill['due_at'] = app(OrganisationCalendar::class)->stored($due)->format('Y-m-d H:i:s');
        }

        $assignee = $query['assignee'] ?? null;

        if (is_string($assignee) && ctype_digit($assignee) && $actor->can(Permission::TaskAssign->value)
            && app(RecordVisibilityResolver::class)->assignableUsers($actor, Task::permissionGroup())->whereKey((int) $assignee)->exists()) {
            $prefill[OwnerSelect::FIELD] = (int) $assignee;
        }

        return $prefill;
    }

    /**
     * The comment box of the Comment action (D-17, D-20) for the task
     * page's always-visible composer (D-27, App\Livewire\Tasks\TaskComposer):
     * the very same editor — no formatting, no uploads, the "@" list of
     * TaskService::mentionableUsers(), validated on the plain text
     * CommentDraft makes of it — so the two ways of commenting never drift.
     * Its form must carry the task as its record (the notice helper reads it).
     */
    public static function composerEditor(): RichEditor
    {
        return self::commentEditor();
    }

    /**
     * Whether the start and update notices reach someone (the composer's
     * progress mode says so under its box, like the dialogs do).
     */
    public static function progressNoticeHelper(?Model $record): ?string
    {
        return self::reportNoticeHelper($record);
    }

    /** The shared one-click complete: no dialog unless checklist items are open (D-25). */
    private static function oneClickComplete(string $name): Action
    {
        return Action::make($name)
            ->icon(Heroicon::OutlinedCheckCircle)
            ->modalHeading(__('tasks.actions.complete_heading'))
            ->modalSubmitActionLabel(__('tasks.actions.complete_submit'))
            ->modalHidden(static fn (?Model $record): bool => ! $record instanceof Task || self::openItemsWarning($record) === null)
            ->schema([
                Callout::make(static fn (?Model $record): ?string => $record instanceof Task ? self::openItemsWarning($record) : null)
                    ->key('checklistWarning')
                    ->description(__('tasks.helpers.checklist_complete_anyway'))
                    ->warning()
                    ->visible(static fn (?Model $record): bool => $record instanceof Task && self::openItemsWarning($record) !== null),

                Section::make(__('tasks.sections.notes'))
                    ->columns(1)
                    ->schema([
                        Textarea::make('completion_note')
                            ->label(__('tasks.fields.completion_note'))
                            ->rows(3)
                            ->maxLength(2000),
                    ]),
            ])
            ->visible(static fn (?Model $record): bool => $record instanceof Task && TaskTransition::Complete->appliesTo($record))
            ->authorize(static fn (?Model $record): bool => $record instanceof Task && self::can('complete', $record))
            ->action(function (Task $record, array $data): void {
                self::attempt(function () use ($record, $data): void {
                    app(TaskService::class)->complete($record, self::actor(), (string) ($data['completion_note'] ?? ''));

                    self::completedNotice($record);
                });
            });
    }

    /**
     * "Task completed", with «تراجع» when the reader may reopen the task they
     * just completed — the policy's `reopen` answer for a completed task.
     */
    private static function completedNotice(Task $task): void
    {
        $notification = Notification::make()->title(__('tasks.notifications.completed'))->success();

        if (self::can('reopen', $task)) {
            $notification->actions([
                Action::make('undo')
                    ->label(__('tasks.actions.undo'))
                    ->link()
                    ->dispatch(self::UNDO_COMPLETE_EVENT, ['task' => (int) $task->getKey()])
                    ->close(),
            ]);
        }

        $notification->send();
    }

    /** One status move: its label, icon and colour, shown when the state admits it, authorised by its policy verb. */
    private static function transitionAction(string $name, TaskTransition $transition): Action
    {
        return Action::make($name)
            ->label($transition->getLabel())
            ->icon($transition->getIcon())
            ->color($transition->getColor())
            ->visible(static fn (?Model $record): bool => $record instanceof Task && $transition->appliesTo($record))
            ->authorize(static fn (?Model $record): bool => $record instanceof Task && self::can($transition->ability(), $record));
    }

    /** A reschedule chip: shown on an open task, authorised by `update` (the dates are the details, D-14). */
    private static function rescheduleAction(string $name, DueShortcut $shortcut): Action
    {
        return Action::make($name)
            ->label($shortcut->getLabel())
            ->icon($shortcut->getIcon())
            ->color('gray')
            ->visible(static fn (?Model $record): bool => $record instanceof Task && $record->isOpen() && ! $record->trashed())
            ->authorize(static fn (?Model $record): bool => $record instanceof Task && self::can('update', $record));
    }

    /** Moves the due date through TaskService and says where it landed, on the organisation clock. */
    private static function moveDue(Task $task, ?Carbon $dueAt): void
    {
        if ($dueAt === null) {
            return;
        }

        self::attempt(function () use ($task, $dueAt): void {
            app(TaskService::class)->moveDue($task, $dueAt, self::actor());

            Notification::make()
                ->title(__('tasks.notifications.rescheduled', ['due' => app(OrganisationCalendar::class)->local($dueAt)->format('Y-m-d H:i')]))
                ->success()
                ->send();
        });
    }

    /**
     * Who quick add offers as assignee to a holder of `task.assign`: the
     * active users in their reach (RecordVisibilityResolver::assignableUsers,
     * the same list the full form offers), the reader first.
     *
     * @return array<int, string>
     */
    private static function quickAddAssignees(): array
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return [];
        }

        /** @var array<int, string> $assignable */
        $assignable = app(RecordVisibilityResolver::class)->assignableUsers($actor, Task::permissionGroup())->pluck('name', 'id')->all();

        // `+` keeps the user ids as keys (a spread would renumber them) and drops the reader's second entry.
        return [(int) $actor->getKey() => $actor->name] + $assignable;
    }

    private static function canAssign(): bool
    {
        return auth()->user()?->can(Permission::TaskAssign->value) ?? false;
    }

    /**
     * What quick add hands TaskService::create(): the submitted assignee only
     * from a holder of `task.assign` (the service re-checks the reach), the
     * reader otherwise; the due moment from the chip.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function quickAddAttributes(array $data, User $actor): array
    {
        $assigneeId = $data['assignee_id'] ?? null;
        $shortcut = self::shortcut($data['due_shortcut'] ?? null) ?? DueShortcut::None;
        $priority = $data['priority'] ?? null;

        return [
            'title' => trim((string) ($data['title'] ?? '')),
            'kind' => TaskKind::Task->value,
            'priority' => ($priority instanceof TaskPriority ? $priority : TaskPriority::tryFrom((string) $priority) ?? TaskPriority::Medium)->value,
            'due_at' => app(DueShortcuts::class)->resolve($shortcut, null, self::dateString($data['due_on'] ?? null)),
            'assignee_id' => self::canAssign() && (is_int($assigneeId) || (is_string($assigneeId) && ctype_digit($assigneeId)))
                ? (int) $assigneeId
                : (int) $actor->getKey(),
        ];
    }

    /**
     * Keeps the quick-add values for the create page in the reader's server
     * session under a random one-time token, so the typed title never
     * travels in an address (server logs, history, Referer). One slot: a
     * newer «خيارات أكثر» replaces an unused older one.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function stashPrefill(array $attributes): string
    {
        $token = Str::random(40);

        session()->put(self::PREFILL_SESSION_KEY, ['token' => $token, 'values' => self::createQuery($attributes)]);

        return $token;
    }

    /**
     * The quick-add values as the create page reads them (createPrefill()).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    private static function createQuery(array $attributes): array
    {
        $dueAt = $attributes['due_at'] ?? null;

        return array_filter([
            'title' => (string) $attributes['title'],
            'priority' => (string) $attributes['priority'],
            'due' => $dueAt instanceof DateTimeInterface ? app(OrganisationCalendar::class)->local($dueAt)->format('Y-m-d H:i') : '',
            'assignee' => self::canAssign() ? (string) $attributes['assignee_id'] : '',
        ], static fn (string $value): bool => $value !== '');
    }

    /** "Y-m-d H:i" on the organisation clock, strictly; null for anything else. */
    private static function organisationMoment(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $moment = CarbonImmutable::createFromFormat('!Y-m-d H:i', $value, app(OrganisationCalendar::class)->timezone());
        } catch (InvalidFormatException) {
            return null;
        }

        // A day or an hour past the calendar ("2026-02-31", "24:00") rolls over; the round trip refuses it.
        if (! $moment instanceof CarbonImmutable || $moment->format('Y-m-d H:i') !== $value) {
            return null;
        }

        return $moment;
    }

    /** A picked date as "Y-m-d", whatever shape the picker's state took. */
    private static function dateString(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr(trim($value), 0, 10) : null;
    }

    private static function shortcut(mixed $value): ?DueShortcut
    {
        if ($value instanceof DueShortcut) {
            return $value;
        }

        return is_string($value) ? DueShortcut::tryFrom($value) : null;
    }

    /**
     * @param  list<DueShortcut>  $shortcuts
     * @return array<string, string>
     */
    private static function shortcutOptions(array $shortcuts): array
    {
        $options = [];

        foreach ($shortcuts as $shortcut) {
            $options[$shortcut->value] = $shortcut->getLabel();
        }

        return $options;
    }

    /**
     * @param  list<DueShortcut>  $shortcuts
     * @return array<string, Heroicon>
     */
    private static function shortcutIcons(array $shortcuts): array
    {
        $icons = [];

        foreach ($shortcuts as $shortcut) {
            $icons[$shortcut->value] = $shortcut->getIcon();
        }

        return $icons;
    }

    private static function can(string $ability, Task $task): bool
    {
        return auth()->user()?->can($ability, $task) ?? false;
    }

    /**
     * "2 of 5 checklist items are not done" for the Complete dialog, or null
     * when every item is ticked or there is no checklist. The plural form
     * follows the checklist's size, which the counted noun agrees with.
     */
    private static function openItemsWarning(Task $task): ?string
    {
        ['done' => $done, 'total' => $total] = TaskChecklist::counts($task);
        $open = $total - $done;

        if ($open <= 0) {
            return null;
        }

        return trans_choice('tasks.confirmations.checklist_open', $total, ['count' => $open, 'total' => $total]);
    }

    /**
     * The bulk Complete dialog's warning over the open tasks of the
     * selection, followed by the note that completing is still allowed; null
     * when none of them has an unticked item.
     *
     * @param  iterable<mixed>  $records
     */
    private static function openItemsWarningAcross(iterable $records): ?string
    {
        $ids = [];

        foreach ($records as $record) {
            if ($record instanceof Task && $record->isOpen() && ! $record->trashed()) {
                $ids[] = (int) $record->getKey();
            }
        }

        ['open' => $open, 'total' => $total] = TaskChecklist::openAcross($ids);

        if ($open <= 0) {
            return null;
        }

        return trans_choice('tasks.confirmations.checklist_open_bulk', $total, ['count' => $open, 'total' => $total])
            .' '.__('tasks.helpers.checklist_complete_anyway');
    }

    /**
     * The task's checklist items as the tick dialog lists them: id => text,
     * in order. One query; the text is plain and escaped by the field.
     *
     * @return array<int, string>
     */
    private static function itemOptions(Task $task): array
    {
        return collect(TaskChecklist::formState($task))
            ->mapWithKeys(static fn (array $item): array => [$item['id'] => $item['body']])
            ->all();
    }

    /**
     * The ids of the task's ticked items.
     *
     * @return list<int>
     */
    private static function doneItemIds(Task $task): array
    {
        return TaskChecklistItem::query()
            ->where('task_id', $task->getKey())
            ->whereNotNull('completed_at')
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * The well-formed ids among those a form state holds.
     *
     * @return list<int>
     */
    private static function idList(mixed $values): array
    {
        return is_array($values) ? self::userIds($values) : [];
    }

    /**
     * The start and update modals say the assigner is told only when the
     * service will actually tell someone — not when the actor is the
     * assigner, nor when the recipient left, was disabled or may no longer
     * open the task.
     */
    private static function reportNoticeHelper(?Model $record): ?string
    {
        $actor = auth()->user();

        if (! $record instanceof Task || ! $actor instanceof User || ! app(TaskService::class)->reportsReachSomeone($record, $actor)) {
            return null;
        }

        return __('tasks.helpers.update_notifies');
    }

    /**
     * The comment modal says the participants are told only when the service
     * will actually tell someone — not on a task nobody else takes part in,
     * nor when every other participant left, was disabled or may no longer
     * open the task.
     */
    private static function commentNoticeHelper(?Model $record): ?string
    {
        $actor = auth()->user();

        if (! $record instanceof Task || ! $actor instanceof User || ! app(TaskService::class)->commentsReachSomeone($record, $actor)) {
            return null;
        }

        return __('tasks.helpers.comment_notifies');
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        return $actor;
    }

    /**
     * Runs a service call and turns a refused transition — or a note the
     * service refuses although the form let it through — into a danger
     * notification.
     */
    private static function attempt(callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidTaskTransitionException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        } catch (ValidationException $exception) {
            Notification::make()->title((string) Arr::first(Arr::flatten($exception->errors())))->danger()->send();
        }
    }
}
