<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Schemas;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\User;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * One task (decisions A-10, D-14, D-17): what it is, how the work is going
 * (the thread of progress entries and comments), when it is due, the records it is linked to, who it is
 * assigned to and by whom, how it repeats and who created it. Links to a
 * linked record, and to the first task of its series, appear only when the
 * reader may open it — an employee (D-15) reads a handed-out task linked to
 * a lead as plain text, never as a link they would be refused.
 */
final class TaskInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('tasks.sections.details'))
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('status')
                                ->label(__('tasks.fields.status'))
                                ->badge(),
                            TextEntry::make('priority')
                                ->label(__('tasks.fields.priority'))
                                ->badge(),
                            TextEntry::make('kind')
                                ->label(__('tasks.fields.kind'))
                                ->badge()
                                ->color('gray'),
                            TextEntry::make('assignee.name')
                                ->label(__('tasks.fields.assignee'))
                                ->placeholder(__('assignment.placeholders.unassigned')),
                        ]),
                        TextEntry::make('title')
                            ->label(__('tasks.fields.title'))
                            ->weight('semibold')
                            ->columnSpanFull(),
                        TextEntry::make('description')
                            ->label(__('tasks.fields.description'))
                            ->placeholder(__('common.placeholders.empty'))
                            ->extraAttributes(['class' => 'whitespace-pre-line'])
                            ->columnSpanFull(),
                    ])
                    ->columns(1),

                self::threadSection(),

                Section::make(__('tasks.sections.schedule'))
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('due_at')
                                ->label(__('tasks.fields.due_at'))
                                ->dateTime('Y-m-d H:i')
                                ->color(fn (Task $record): ?string => self::dueColor($record))
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('reminder_at')
                                ->label(__('tasks.fields.reminder_at'))
                                ->dateTime('Y-m-d H:i')
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('starts_at')
                                ->label(__('tasks.fields.starts_at'))
                                ->dateTime('Y-m-d H:i')
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('ends_at')
                                ->label(__('tasks.fields.ends_at'))
                                ->dateTime('Y-m-d H:i')
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('completed_at')
                                ->label(__('tasks.fields.completed_at'))
                                ->dateTime('Y-m-d H:i')
                                ->placeholder(__('common.placeholders.empty')),
                        ]),
                    ])
                    ->columns(1),

                Section::make(__('tasks.sections.related'))
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('deal.title')
                                ->label(__('tasks.fields.deal'))
                                ->url(fn (Task $record): ?string => $record->deal === null ? null : TaskResource::urlForSubject($record->deal))
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('lead.full_name')
                                ->label(__('tasks.fields.lead'))
                                ->state(fn (Task $record): ?string => $record->lead?->full_name)
                                ->url(fn (Task $record): ?string => $record->lead === null ? null : TaskResource::urlForSubject($record->lead))
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('contact.full_name')
                                ->label(__('tasks.fields.contact'))
                                ->state(fn (Task $record): ?string => $record->contact?->full_name)
                                ->url(fn (Task $record): ?string => $record->contact === null ? null : TaskResource::urlForSubject($record->contact))
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('account.name')
                                ->label(__('tasks.fields.account'))
                                ->url(fn (Task $record): ?string => $record->account === null ? null : TaskResource::urlForSubject($record->account))
                                ->placeholder(__('common.placeholders.empty')),
                        ]),
                    ])
                    ->columns(1),

                Section::make(__('tasks.sections.recurrence'))
                    ->visible(fn (Task $record): bool => $record->repeats() || $record->series_id !== null)
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('recurrence_summary')
                                ->label(__('tasks.fields.recurrence_frequency'))
                                ->state(fn (Task $record): string => self::recurrenceSummary($record)),
                            TextEntry::make('recurrence_ends_at')
                                ->label(__('tasks.fields.recurrence_ends_at'))
                                ->date('Y-m-d')
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('series.title')
                                ->label(__('tasks.fields.series'))
                                ->url(fn (Task $record): ?string => $record->series === null || auth()->user()?->can('view', $record->series) !== true ? null : TaskResource::getUrl('view', ['record' => $record->series]))
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('occurrences_count')
                                ->label(__('tasks.fields.occurrences_count'))
                                ->state(fn (Task $record): int => $record->occurrences()->count()),
                        ]),
                    ])
                    ->columns(1),

                Section::make(__('tasks.sections.audit'))
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('activities_count')
                                ->label(__('tasks.fields.activities_count'))
                                ->state(fn (Task $record): int => $record->activities()->count()),
                            TextEntry::make('creator.name')
                                ->label(__('tasks.fields.created_by'))
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('assigner.name')
                                ->label(__('tasks.fields.assigned_by'))
                                ->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('created_at')
                                ->label(__('tasks.fields.created_at'))
                                ->dateTime('Y-m-d H:i'),
                            TextEntry::make('updated_at')
                                ->label(__('tasks.fields.updated_at'))
                                ->dateTime('Y-m-d H:i'),
                        ]),
                    ])
                    ->columns(1)
                    ->collapsible(),
            ])
            ->columns(1);
    }

    /**
     * The thread (D-14 amendment, 2026-09-28; D-17): progress entries and
     * comments together, newest first — each marked as an update or a
     * comment, with its author, its time in the organisation's timezone, the
     * status a progress entry moved the task to as a badge, and the author's
     * text as plain text. The thread and its authors are loaded in two
     * queries whatever its length; the reader who may report on the task but
     * not edit it is told where their actions are.
     */
    private static function threadSection(): Section
    {
        return Section::make(__('tasks.sections.thread'))
            ->description(static function (?Model $record): ?string {
                $user = auth()->user();

                return $record instanceof Task && $user instanceof User && $user->can('progress', $record) && ! $user->can('update', $record)
                    ? __('tasks.helpers.handed_out')
                    : null;
            })
            ->schema([
                RepeatableEntry::make('updates')
                    ->hiddenLabel()
                    ->state(static fn (?Model $record): ?Collection => $record instanceof Task ? $record->loadMissing('updates.author')->updates : null)
                    ->placeholder(__('tasks.empty.thread'))
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('kind')
                                ->label(__('tasks.fields.update_kind'))
                                ->badge(),
                            TextEntry::make('author_name')
                                ->label(__('tasks.fields.update_author'))
                                ->state(static fn (?Model $record): ?string => $record instanceof TaskUpdate ? self::authorName($record) : null),
                            TextEntry::make('created_at')
                                ->label(__('tasks.fields.update_created_at'))
                                ->dateTime('Y-m-d H:i'),
                            TextEntry::make('status')
                                ->label(__('tasks.fields.update_status'))
                                ->badge()
                                ->visible(static fn (?Model $record): bool => $record instanceof TaskUpdate && $record->status !== null),
                        ]),
                        TextEntry::make('body')
                            ->label(static fn (?Model $record): string => $record instanceof TaskUpdate && $record->isComment() ? __('tasks.fields.comment_body') : __('tasks.fields.update_body'))
                            ->extraAttributes(['class' => 'whitespace-pre-line'])
                            ->visible(static fn (?Model $record): bool => $record instanceof TaskUpdate && filled($record->body))
                            ->columnSpanFull(),
                    ]),
            ])
            ->columns(1);
    }

    /** The author's name, marked when their account was deleted, or a fallback when it is gone. */
    private static function authorName(TaskUpdate $update): string
    {
        $author = $update->author;

        if ($author === null) {
            return __('tasks.empty.deleted_author');
        }

        return $author->trashed()
            ? __('tasks.empty.deleted_author_named', ['name' => $author->name])
            : $author->name;
    }

    /** "Every 2 weeks", "Every day" — or "does not repeat", in the reader's language. */
    public static function recurrenceSummary(Task $record): string
    {
        $frequency = $record->recurrence_frequency;

        if (! $frequency->repeats()) {
            return $frequency->getLabel();
        }

        $interval = max(1, (int) ($record->recurrence_interval ?? 1));

        return trans_choice('tasks.recurrence.'.$frequency->value, $interval, ['count' => $interval]);
    }

    /** Danger once overdue, warning on the due day, nothing otherwise. */
    public static function dueColor(Task $record): ?string
    {
        if ($record->isOverdue()) {
            return 'danger';
        }

        return $record->isDueToday() ? 'warning' : null;
    }
}
