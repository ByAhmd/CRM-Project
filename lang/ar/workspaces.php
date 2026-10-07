<?php

declare(strict_types=1);

// The workspaces (D-23): the workspace screen, the top-bar switcher and the
// home pages of the Reports and Administration workspaces (the Tasks home,
// «عملي», is lang/*/my_work.php, D-27). Workspace
// names and descriptions are enum labels (enums.workspace_panel*).
return [

    'navigation' => [
        'reports_home' => 'كل التقارير',
        'admin_home' => 'نظرة عامة',
    ],

    'pages' => [
        'screen' => [
            'title' => 'لوحة التحكم',
            'subheading' => 'اختر مساحة العمل',
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
