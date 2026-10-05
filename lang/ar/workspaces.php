<?php

declare(strict_types=1);

// The workspaces (D-23): the workspace screen, the top-bar switcher and the
// home pages of the Tasks, Reports and Administration workspaces. Workspace
// names and descriptions are enum labels (enums.workspace_panel*).
return [

    'navigation' => [
        'tasks_home' => 'نظرة عامة',
        'reports_home' => 'كل التقارير',
        'admin_home' => 'نظرة عامة',
    ],

    'pages' => [
        'screen' => [
            'title' => 'لوحة التحكم',
            'subheading' => 'اختر مساحة العمل',
        ],
        'tasks_home' => [
            'title' => 'نظرة عامة على المهام',
            'subheading' => 'مهامك المستحقة اليوم والمتأخرة، والمتابعات القادمة.',
        ],
        'reports_home' => [
            'title' => 'التقارير',
            'subheading' => 'التقارير التي يحق لك فتحها.',
        ],
        'admin_home' => [
            'title' => 'الإدارة',
            'subheading' => 'شاشات الإدارة التي يحق لك فتحها.',
        ],
    ],

    'actions' => [
        'enter' => 'دخول',
    ],

    'helpers' => [
        'locked' => 'غير متاح لحسابك',
        'switcher' => 'تبديل مساحة العمل (الحالية: :workspace)',
    ],

    'empty' => [
        'reports_home' => [
            'heading' => 'لا تقارير متاحة لك',
            'description' => 'لا يمنحك دورك فتح أي تقرير. راجع مدير النظام إن احتجت إلى ذلك.',
        ],
        'admin_home' => [
            'heading' => 'لا شاشات إدارة متاحة لك',
            'description' => 'لا يمنحك دورك فتح أي شاشة إدارة. راجع مدير النظام إن احتجت إلى ذلك.',
        ],
    ],

];
