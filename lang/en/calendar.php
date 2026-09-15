<?php

declare(strict_types=1);

// Calendar page: tasks, meetings and calls by date (decision D-12).
return [

    'navigation' => 'Calendar',
    'title' => 'Calendar',

    'legend' => [
        'heading' => 'Legend',
        'tasks' => 'Tasks by priority',
        'meetings' => 'Meetings',
        'calls' => 'Calls',
        'completed' => 'Completed',
    ],

    'views' => [
        'month' => 'Month',
        'week' => 'Week',
        'day' => 'Day',
        'list' => 'List',
    ],

    'buttons' => [
        'today' => 'Today',
        'prev' => 'Previous',
        'next' => 'Next',
    ],

    'texts' => [
        'all_day' => 'All day',
        'no_events' => 'Nothing scheduled in this period',
        'more' => '+:count more',
        'truncated' => 'This period holds more than :count entries, so only the first :count are shown. Switch to the week or day view to see the rest.',
    ],

    'actions' => [
        'create_task' => 'Add task',
        'create_task_heading' => 'Add a task',
        'create_task_submit' => 'Add task',
        'edit_task' => 'Edit task',
        'edit_task_heading' => 'Edit task',
        'edit_task_submit' => 'Save changes',
        'complete' => 'Complete',
    ],

    'notifications' => [
        'moved' => ':task moved to :date',
        'created' => 'Task added',
        'updated' => 'Task updated',
        'refused' => 'The task could not be moved',
    ],

    'validation' => [
        'range_too_large' => 'The calendar loads at most :days days at a time.',
        'invalid_date' => 'That date is not valid.',
    ],

    'empty' => [
        'description' => 'Click a day to add a task, or log a meeting or a call from a record.',
    ],

];
