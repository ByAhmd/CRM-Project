<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskTemplates\Schemas;

use App\Enums\RoutineFrequency;
use App\Enums\TaskPriority;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Tasks\RoutineTaskService;
use App\Services\Tasks\TaskChecklist;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Create / edit a routine task (decision D-22). The weekday shows for a
 * weekly routine and the day of the month (1–28) for a monthly one; the due
 * time is a plain time of day in the organisation's time (the picker is
 * pinned to app.timezone, so no timezone conversion touches it). The
 * assignees are the users the actor may hand tasks to
 * (RecordVisibilityResolver::assignableUsers),
 * plus — on an edit — the routine's current assignees whatever their state,
 * so the form shows who is on it; RoutineTaskService refuses to save an
 * assignee the actor may not reach, so one who left that reach has to be
 * taken off. The checklist (D-25) is a list of lines copied, unticked, into
 * every task the routine creates. The service re-validates everything the
 * form checks.
 */
final class TaskTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('task_templates.sections.details'))
                    ->schema([
                        TextInput::make('title')
                            ->label(__('task_templates.fields.title'))
                            ->placeholder(__('task_templates.placeholders.title'))
                            ->required()
                            ->maxLength(RoutineTaskService::TITLE_MAX)
                            ->columnSpanFull(),

                        Select::make('priority')
                            ->label(__('task_templates.fields.priority'))
                            ->options(TaskPriority::class)
                            ->default(TaskPriority::Medium->value)
                            ->required()
                            ->native(false),

                        Toggle::make('is_active')
                            ->label(__('task_templates.fields.is_active'))
                            ->helperText(__('task_templates.helpers.is_active'))
                            ->default(true),

                        Textarea::make('description')
                            ->label(__('task_templates.fields.description'))
                            ->placeholder(__('task_templates.placeholders.description'))
                            ->rows(4)
                            ->maxLength(RoutineTaskService::DESCRIPTION_MAX)
                            ->columnSpanFull(),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),

                Section::make(__('task_templates.sections.schedule'))
                    ->description(__('task_templates.helpers.schedule'))
                    ->schema([
                        Select::make('frequency')
                            ->label(__('task_templates.fields.frequency'))
                            ->options(RoutineFrequency::class)
                            ->default(RoutineFrequency::Weekly->value)
                            ->required()
                            ->live()
                            ->native(false),

                        Select::make('weekday')
                            ->label(__('task_templates.fields.weekday'))
                            ->options(self::weekdayOptions())
                            ->required(fn (Get $get): bool => self::frequency($get) === RoutineFrequency::Weekly)
                            ->visible(fn (Get $get): bool => self::frequency($get) === RoutineFrequency::Weekly)
                            ->native(false),

                        Select::make('day_of_month')
                            ->label(__('task_templates.fields.day_of_month'))
                            ->helperText(__('task_templates.helpers.day_of_month'))
                            ->options(self::dayOfMonthOptions())
                            ->required(fn (Get $get): bool => self::frequency($get) === RoutineFrequency::Monthly)
                            ->visible(fn (Get $get): bool => self::frequency($get) === RoutineFrequency::Monthly)
                            ->native(false),

                        // A time of day, not a moment: the picker would otherwise
                        // read and write it through the panel's organisation
                        // timezone against app.timezone and shift it by their
                        // difference. Pinned to app.timezone, it converts
                        // nothing, and RoutineTaskService reads the stored
                        // value as organisation time.
                        TimePicker::make('due_time')
                            ->label(__('task_templates.fields.due_time'))
                            ->helperText(__('task_templates.helpers.due_time'))
                            ->seconds(false)
                            ->timezone(static fn (): string => (string) config('app.timezone'))
                            ->default(RoutineTaskService::DEFAULT_DUE_TIME)
                            ->required(),

                        TextInput::make('due_in_days')
                            ->label(__('task_templates.fields.due_in_days'))
                            ->helperText(__('task_templates.helpers.due_in_days'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(RoutineTaskService::DUE_IN_DAYS_MAX)
                            ->default(0)
                            ->required()
                            ->extraInputAttributes(['dir' => 'ltr']),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),

                Section::make(__('task_templates.sections.assignees'))
                    ->schema([
                        Select::make('assignees')
                            ->label(__('task_templates.fields.assignees'))
                            ->helperText(__('task_templates.helpers.assignees'))
                            ->multiple()
                            ->options(fn (?TaskTemplate $record): array => self::assigneeOptions($record))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->native(false),
                    ])
                    ->columns(1),

                // The steps every task of the routine carries, copied unticked
                // (D-25). A plain repeater: RoutineTaskService replaces the
                // rows and records the change.
                Section::make(__('task_templates.sections.checklist'))
                    ->description(__('task_templates.helpers.checklist'))
                    ->schema([
                        Repeater::make('checklist')
                            ->label(__('task_templates.sections.checklist'))
                            ->hiddenLabel()
                            ->simple(
                                TextInput::make('body')
                                    ->label(__('task_templates.fields.checklist_item'))
                                    ->placeholder(__('task_templates.placeholders.checklist_item'))
                                    ->required()
                                    ->maxLength(TaskChecklist::BODY_MAX),
                            )
                            ->addActionLabel(__('task_templates.actions.add_checklist_item'))
                            ->reorderable()
                            ->maxItems(TaskChecklist::MAX_ITEMS)
                            ->defaultItems(0)
                            ->default([]),
                    ])
                    ->columns(1)
                    ->collapsible(),
            ])
            ->columns(1);
    }

    /**
     * The service's refusal, keyed to the form's state paths so each message
     * shows under its own field.
     */
    public static function formErrors(ValidationException $exception): ValidationException
    {
        $messages = [];

        foreach ($exception->errors() as $key => $errors) {
            $messages['data.'.$key] = $errors;
        }

        return ValidationException::withMessages($messages);
    }

    /**
     * Sunday … Saturday (0 … 6), named from the language files rather than
     * the date library's locale.
     *
     * @return array<int, string>
     */
    public static function weekdayOptions(): array
    {
        $options = [];

        foreach (range(0, 6) as $day) {
            $options[$day] = (string) __('task_templates.options.weekdays.'.$day);
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private static function dayOfMonthOptions(): array
    {
        $options = [];

        foreach (range(1, RoutineTaskService::DAY_OF_MONTH_MAX) as $day) {
            $options[$day] = (string) $day;
        }

        return $options;
    }

    /**
     * The users the actor may hand tasks to, plus the routine's current
     * assignees.
     *
     * @return array<int, string>
     */
    private static function assigneeOptions(?TaskTemplate $record): array
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return [];
        }

        /** @var array<int, string> $options */
        $options = app(RecordVisibilityResolver::class)
            ->assignableUsers($actor, Task::permissionGroup())
            ->pluck('name', 'id')
            ->all();

        if ($record instanceof TaskTemplate && $record->exists) {
            foreach ($record->assignees()->pluck('users.name', 'users.id')->all() as $id => $name) {
                $options[(int) $id] ??= (string) $name;
            }
        }

        return $options;
    }

    private static function frequency(Get $get): ?RoutineFrequency
    {
        $frequency = $get('frequency');

        if ($frequency instanceof RoutineFrequency) {
            return $frequency;
        }

        return is_string($frequency) && $frequency !== '' ? RoutineFrequency::tryFrom($frequency) : null;
    }
}
