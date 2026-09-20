<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\RecurrenceFrequency;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Filament\Support\OwnerSelect;
use App\Filament\Support\SubjectPickers;
use App\Models\Task;
use App\Services\Tasks\TaskService;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * Create / edit task (decision A-10). The status is not part of the form:
 * it changes through the complete, cancel and reopen actions so every
 * transition is audited; on edit it is shown read-only. Meetings and calls
 * carry a start and an end (the calendar span); the end may not precede the
 * start and TaskService applies the same rule server-side. The recurrence
 * fields appear once a frequency other than "does not repeat" is chosen.
 *
 * With $withSubjects the form carries the related-record pickers (all
 * optional: a task may be a personal to-do); on a relation manager the
 * owner record is the subject and the pickers are left out. The assignee
 * field is OwnerSelect's `owner_id`, which TaskService maps to
 * `assignee_id`.
 */
final class TaskForm
{
    public static function configure(Schema $schema, bool $withSubjects = true): Schema
    {
        return $schema
            ->components([
                Section::make(__('tasks.sections.details'))
                    ->schema([
                        TextInput::make('title')
                            ->label(__('tasks.fields.title'))
                            ->placeholder(__('tasks.placeholders.title'))
                            ->required()
                            ->maxLength(200)
                            ->columnSpanFull(),

                        Select::make('kind')
                            ->label(__('tasks.fields.kind'))
                            ->options(TaskKind::class)
                            ->default(TaskKind::Task->value)
                            ->required()
                            ->live()
                            ->native(false),

                        Select::make('priority')
                            ->label(__('tasks.fields.priority'))
                            ->options(TaskPriority::class)
                            ->default(TaskPriority::Medium->value)
                            ->required()
                            ->native(false),

                        Textarea::make('description')
                            ->label(__('tasks.fields.description'))
                            ->placeholder(__('tasks.placeholders.description'))
                            ->rows(4)
                            ->maxLength(5000)
                            ->columnSpanFull(),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),

                Section::make(__('tasks.sections.schedule'))
                    ->schema([
                        DateTimePicker::make('due_at')
                            ->label(__('tasks.fields.due_at'))
                            ->native(false)
                            ->seconds(false)
                            ->nullable(),

                        DateTimePicker::make('reminder_at')
                            ->label(__('tasks.fields.reminder_at'))
                            ->helperText(__('tasks.helpers.reminder_at'))
                            ->native(false)
                            ->seconds(false)
                            ->nullable(),

                        DateTimePicker::make('starts_at')
                            ->label(__('tasks.fields.starts_at'))
                            ->native(false)
                            ->seconds(false)
                            ->nullable()
                            ->visible(fn (Get $get): bool => self::hasTimeSpan($get)),

                        DateTimePicker::make('ends_at')
                            ->label(__('tasks.fields.ends_at'))
                            ->helperText(__('tasks.helpers.ends_at_after_starts_at'))
                            ->native(false)
                            ->seconds(false)
                            ->nullable()
                            ->visible(fn (Get $get): bool => self::hasTimeSpan($get))
                            ->rules([
                                fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    $startsAt = $get('starts_at');

                                    if (blank($value) || blank($startsAt)) {
                                        return;
                                    }

                                    if (Carbon::parse((string) $value)->lessThan(Carbon::parse((string) $startsAt))) {
                                        $fail(__('tasks.validation.ends_before_starts'));
                                    }
                                },
                            ]),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),

                ...$withSubjects ? [
                    Section::make(__('tasks.sections.related'))
                        ->description(__('tasks.helpers.related'))
                        ->schema(SubjectPickers::components(required: false))
                        ->columns(['default' => 1, 'lg' => 2]),
                ] : [],

                Section::make(__('tasks.sections.assignment'))
                    ->schema([
                        ...OwnerSelect::components(Task::permissionGroup()),

                        Placeholder::make('status_display')
                            ->label(__('tasks.fields.status'))
                            ->content(fn (?Task $record): string => $record?->status->getLabel() ?? '')
                            ->helperText(__('tasks.helpers.status_readonly'))
                            ->visible(fn (?Task $record): bool => $record !== null),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),

                Section::make(__('tasks.sections.recurrence'))
                    ->description(__('tasks.helpers.recurrence'))
                    ->schema([
                        Select::make('recurrence_frequency')
                            ->label(__('tasks.fields.recurrence_frequency'))
                            ->options(RecurrenceFrequency::class)
                            ->default(RecurrenceFrequency::None->value)
                            ->required()
                            ->live()
                            ->native(false),

                        TextInput::make('recurrence_interval')
                            ->label(__('tasks.fields.recurrence_interval'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(255)
                            ->default(1)
                            ->required(fn (Get $get): bool => self::repeats($get))
                            ->visible(fn (Get $get): bool => self::repeats($get))
                            ->extraInputAttributes(['dir' => 'ltr']),

                        DatePicker::make('recurrence_ends_at')
                            ->label(__('tasks.fields.recurrence_ends_at'))
                            ->native(false)
                            ->nullable()
                            ->visible(fn (Get $get): bool => self::repeats($get)),
                    ])
                    ->columns(['default' => 1, 'lg' => 2])
                    ->collapsible(),
            ])
            ->columns(1);
    }

    /** Meetings and calls carry a start and an end — the same rule TaskService applies. */
    private static function hasTimeSpan(Get $get): bool
    {
        $kind = self::kindFor($get);

        return $kind !== null && TaskService::kindHasTimeSpan($kind);
    }

    private static function kindFor(Get $get): ?TaskKind
    {
        $kind = $get('kind');

        if ($kind instanceof TaskKind) {
            return $kind;
        }

        return is_string($kind) && $kind !== '' ? TaskKind::tryFrom($kind) : null;
    }

    private static function repeats(Get $get): bool
    {
        $frequency = $get('recurrence_frequency');

        if ($frequency instanceof RecurrenceFrequency) {
            return $frequency->repeats();
        }

        return is_string($frequency) && $frequency !== '' && (RecurrenceFrequency::tryFrom($frequency)?->repeats() ?? false);
    }
}
