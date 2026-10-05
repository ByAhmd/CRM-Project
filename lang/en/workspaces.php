<?php

declare(strict_types=1);

// The workspaces (D-23): the workspace screen, the top-bar switcher and the
// home pages of the Tasks, Reports and Administration workspaces. Workspace
// names and descriptions are enum labels (enums.workspace_panel*).
return [

    'navigation' => [
        'tasks_home' => 'Overview',
        'reports_home' => 'All reports',
        'admin_home' => 'Overview',
    ],

    'pages' => [
        'screen' => [
            'title' => 'Control panel',
            'subheading' => 'Choose a workspace',
        ],
        'tasks_home' => [
            'title' => 'Tasks overview',
            'subheading' => 'Your tasks due today or overdue, and the follow-ups coming up.',
        ],
        'reports_home' => [
            'title' => 'Reports',
            'subheading' => 'The reports you may open.',
        ],
        'admin_home' => [
            'title' => 'Administration',
            'subheading' => 'The administration screens you may open.',
        ],
    ],

    'actions' => [
        'enter' => 'Enter',
    ],

    'helpers' => [
        'locked' => 'Not available for your account',
        'switcher' => 'Switch workspace (current: :workspace)',
    ],

    'empty' => [
        'reports_home' => [
            'heading' => 'No reports available to you',
            'description' => 'Your role does not open any report. Ask an administrator if you need one.',
        ],
        'admin_home' => [
            'heading' => 'No administration screens available to you',
            'description' => 'Your role does not open any administration screen. Ask an administrator if you need one.',
        ],
    ],

];
