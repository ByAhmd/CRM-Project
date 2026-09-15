<?php

declare(strict_types=1);

// Tasks and follow-ups: assignee, due date, reminders, recurrence (decisions A-10, D-4).
return [

    'navigation' => [
        'label' => 'Tasks',
        'model' => 'Task',
        'plural_model' => 'Tasks',
    ],

    'tabs' => [
        'my' => 'My tasks',
        'today' => 'Due today',
        'overdue' => 'Overdue',
        'upcoming' => 'Upcoming',
        'completed' => 'Completed',
        'all' => 'All',
    ],

    'sections' => [
        'details' => 'Task details',
        'schedule' => 'Schedule',
        'related' => 'Related records',
        'assignment' => 'Assignment and status',
        'recurrence' => 'Recurrence',
        'notes' => 'Notes',
        'audit' => 'History',
    ],

    'fields' => [
        'title' => 'Title',
        'description' => 'Description',
        'kind' => 'Kind',
        'status' => 'Status',
        'priority' => 'Priority',
        'due_at' => 'Due',
        'starts_at' => 'Starts',
        'ends_at' => 'Ends',
        'reminder_at' => 'Remind at',
        'completed_at' => 'Completed at',
        'assignee' => 'Assignee',
        'lead' => 'Lead',
        'contact' => 'Contact',
        'account' => 'Account',
        'deal' => 'Deal',
        'related' => 'Related record',
        'recurrence_frequency' => 'Repeats',
        'recurrence_interval' => 'Every (units)',
        'recurrence_ends_at' => 'Repeat until',
        'series' => 'First task of the series',
        'occurrences_count' => 'Occurrences scheduled',
        'activities_count' => 'Timeline entries',
        'completion_note' => 'Completion note',
        'created_by' => 'Created by',
        'created_at' => 'Created',
        'updated_at' => 'Last updated',
    ],

    'placeholders' => [
        'title' => 'What needs to be done, in one line',
        'description' => 'Anything the assignee should know',
    ],

    'helpers' => [
        'reminder_at' => 'The assignee is notified once, in the app and by email when a mailer is configured.',
        'recurrence' => 'When a repeating task is completed, the next occurrence is scheduled automatically.',
        'ends_at_after_starts_at' => 'The end must not come before the start.',
        'status_readonly' => 'Use the "Complete", "Cancel" and "Reopen" actions to change the status.',
        'related' => 'Optional: link the task to a lead, a contact, an account or a deal.',
    ],

    'filters' => [
        'status' => 'Status',
        'priority' => 'Priority',
        'kind' => 'Kind',
        'assignee' => 'Assignee',
        'due_from' => 'Due from',
        'due_until' => 'Due until',
        'trashed' => 'Deleted',
    ],

    'actions' => [
        'complete' => 'Complete',
        'complete_heading' => 'Complete task',
        'complete_submit' => 'Complete',
        'cancel' => 'Cancel task',
        'cancel_heading' => 'Cancel task',
        'cancel_submit' => 'Cancel task',
        'reopen' => 'Reopen',
        'reopen_heading' => 'Reopen task',
        'reopen_submit' => 'Reopen',
        'add' => 'Add task',
        'add_heading' => 'Add a task',
        'add_submit' => 'Add task',
        'edit' => 'Edit task',
        'view' => 'View task',
    ],

    'notifications' => [
        'added' => 'Task added',
        'updated' => 'Task updated',
        'completed' => 'Task completed',
        'cancelled' => 'Task cancelled',
        'reopened' => 'Task reopened',
        'bulk_completed' => '{0} No tasks completed|{1} :count task completed|[2,*] :count tasks completed',
        'reminder_title' => 'Task reminder',
        'reminder_body' => 'Your task ":title" is due :due.',
        'overdue_title' => 'Task overdue',
        'overdue_body' => 'Your task ":title" was due :due and is still open.',
        'open' => 'Open task',
        'greeting' => 'Hello :name,',
    ],

    'validation' => [
        'not_open' => 'Only a pending or in-progress task can be completed or cancelled.',
        'not_closed' => 'Only a completed or cancelled task can be reopened.',
        'ends_before_starts' => 'The end must not come before the start.',
        'trashed' => 'A deleted task cannot change status; restore it first.',
    ],

    'empty' => [
        'heading' => 'No tasks yet',
        'description' => 'Add the first task or follow-up to keep the next step in sight.',
    ],

    'recurrence' => [
        'daily' => '{1} Every day|[2,*] Every :count days',
        'weekly' => '{1} Every week|[2,*] Every :count weeks',
        'monthly' => '{1} Every month|[2,*] Every :count months',
    ],

];
