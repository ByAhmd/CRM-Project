<?php

declare(strict_types=1);

// Routine tasks: recurring task templates for routine weekly or monthly work (decision D-22).
return [

    'navigation' => [
        'label' => 'Routine tasks',
        'model' => 'Routine task',
        'plural_model' => 'Routine tasks',
    ],

    'sections' => [
        'details' => 'Routine task',
        'schedule' => 'Schedule',
        'assignees' => 'Assignees',
        'checklist' => 'Checklist',
    ],

    'fields' => [
        'title' => 'Title',
        'description' => 'Description',
        'priority' => 'Priority',
        'is_active' => 'Active',
        'frequency' => 'Repeats',
        'weekday' => 'Day of the week',
        'day_of_month' => 'Day of the month',
        'due_time' => 'Due time',
        'due_in_days' => 'Due after (days)',
        'assignees' => 'Assignees',
        'schedule' => 'Schedule',
        'next_run_on' => 'Next run',
        'assigned_by' => 'Assigned by',
        'checklist_item' => 'Item',
    ],

    'placeholders' => [
        'title' => 'What has to be done each time, in one line',
        'description' => 'Any detail the assignees need each time',
        'paused' => 'Paused',
        'checklist_item' => 'One step of the work, in one line',
    ],

    'helpers' => [
        'schedule' => 'On each scheduled day every assignee gets a task of their own and is told as for any assignment.',
        'is_active' => 'A paused routine creates no tasks until it is resumed.',
        'day_of_month' => 'From 1 to 28, so the day exists in every month.',
        'due_time' => 'Organisation time.',
        'due_in_days' => '0 = due on the scheduled day itself.',
        'assignees' => 'The tasks are handed out in the name of whoever last saved this routine.',
        'checklist' => 'Optional: the steps every task of this routine carries. Each task gets its own copy, unticked.',
    ],

    'options' => [
        'weekdays' => [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ],
    ],

    'summary' => [
        'weekly' => [
            0 => 'Every Sunday',
            1 => 'Every Monday',
            2 => 'Every Tuesday',
            3 => 'Every Wednesday',
            4 => 'Every Thursday',
            5 => 'Every Friday',
            6 => 'Every Saturday',
        ],
        'monthly' => 'Monthly on day :day',
        'due' => '{0} due :time|{1} due :count day later at :time|[2,*] due :count days later at :time',
        'line' => ':schedule · :due',
    ],

    'filters' => [
        'is_active' => 'Active',
        'frequency' => 'Repeats',
        'assignee' => 'Assignee',
    ],

    'actions' => [
        'pause' => 'Pause',
        'pause_heading' => 'Pause the routine task',
        'pause_submit' => 'Pause',
        'resume' => 'Resume',
        'resume_heading' => 'Resume the routine task',
        'resume_submit' => 'Resume',
        'add_checklist_item' => 'Add an item',
    ],

    'confirmations' => [
        'pause' => 'No tasks are created from this routine until it is resumed. The tasks it already created stay as they are.',
        'resume' => 'Its next day is worked out from today.',
        'delete' => 'The routine is deleted; the tasks it already created stay as they are.',
    ],

    'notifications' => [
        'created' => 'Routine task created',
        'saved' => 'Routine task saved',
        'paused' => 'Routine task paused',
        'resumed' => 'Routine task resumed',
        'deleted' => 'Routine task deleted',
    ],

    'validation' => [
        'title_required' => 'Give the routine task a title.',
        'title_too_long' => 'A title may be at most :max characters.',
        'description_too_long' => 'A description may be at most :max characters.',
        'priority_required' => 'Choose a priority.',
        'frequency_required' => 'Choose how often the routine task runs.',
        'weekday_required' => 'Choose the day of the week.',
        'day_of_month_range' => 'Choose a day of the month from 1 to :max.',
        'due_time_invalid' => 'Enter the time the tasks are due in hours and minutes, such as 17:00.',
        'due_in_days_range' => 'Enter a number of days from 0 to :max.',
        'assignees_required' => 'Choose at least one assignee.',
        'assignees_unassignable' => '{1} You cannot hand tasks to :names: the account is disabled or outside the users you can access. Remove this assignee to save.|[2,*] You cannot hand tasks to :names: the accounts are disabled or outside the users you can access. Remove these assignees to save.',
    ],

    'empty' => [
        'heading' => 'No routine tasks yet',
        'description' => 'Set routine weekly or monthly work up once; the CRM hands it out on the day.',
    ],

];
