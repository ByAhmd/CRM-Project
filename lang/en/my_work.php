<?php

declare(strict_types=1);

// D-27: «عملي» (My Work), the Tasks workspace's home.
return [

    'navigation' => [
        'label' => 'My work',
    ],

    'pages' => [
        'title' => 'My work',
        'subheading_delegated' => 'The tasks you handed out to others by due date, and what was done in the last seven days.',
        'subheading_team' => 'The team tasks you can see by due date, and what was done in the last seven days.',
        'subheading' => 'Your tasks by when they are due, and what you completed in the last 7 days.',
    ],

    'fields' => [
        'assigned_to' => 'Assigned to :name',
        'unassigned' => 'Unassigned',
        'handed_out_by' => 'Handed out by :name',
    ],

    'helpers' => [
        'views' => 'Show tasks',
        'summary' => 'Your tasks at a glance',
    ],

    'actions' => [
        'open_board' => 'Open the tasks board',
        'show_more' => '{0} No more tasks|{1} Show :count more task in the task list|[2,*] Show :count more tasks in the task list',
    ],

    'empty' => [
        'heading' => 'Nothing is waiting for you',
        'description' => 'You have no open tasks.',
        'description_add' => 'You have no open tasks. Add one to keep track of it here.',
        'delegated_heading' => 'No open tasks you handed out',
        'delegated_description' => 'What you hand out to the team shows here until it is done.',
        'team_heading' => 'No open team tasks',
        'team_description' => 'Tasks handed out to the team show here by due date.',
        'description_board' => 'You have no open tasks. The tasks board shows what the team is working on.',
        'buckets' => [
            'overdue' => 'No overdue tasks.',
            'today' => 'Nothing is due today.',
            'next_7_days' => 'Nothing is due in the next 7 days.',
            'later' => 'Nothing is due later.',
            'no_date' => 'Every open task of yours has a due date.',
            'completed_recently' => 'You completed no task in the last 7 days.',
        ],
    ],

];
