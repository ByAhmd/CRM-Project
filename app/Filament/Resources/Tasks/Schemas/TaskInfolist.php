<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Schemas;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One task (decision A-10): what it is, when it is due, the records it is
 * linked to, who it is assigned to, how it repeats and who created it.
 * Links to a linked record appear only when the reader may open it.
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
                                ->url(fn (Task $record): ?string => $record->series === null ? null : TaskResource::getUrl('view', ['record' => $record->series]))
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
