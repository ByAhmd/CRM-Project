<?php

declare(strict_types=1);

// Friendly error pages (plan section 7): one title and one message per HTTP status.
return [

    'pages' => [
        '403' => [
            'title' => 'Access denied',
            'message' => 'You do not have permission to open this page. If you think you should, ask an administrator to review your role.',
        ],
        '404' => [
            'title' => 'Page not found',
            'message' => 'The page you are looking for does not exist, or the record was moved or deleted.',
        ],
        '419' => [
            'title' => 'Session expired',
            'message' => 'Your session expired before the form was sent. Reload the page and try again.',
        ],
        '429' => [
            'title' => 'Too many requests',
            'message' => 'Too many attempts in a short time. Wait a moment, then try again.',
        ],
        '500' => [
            'title' => 'Something went wrong',
            'message' => 'An unexpected error stopped this request. It has been recorded; try again in a few minutes.',
        ],
        '503' => [
            'title' => 'Under maintenance',
            'message' => 'The system is being updated and will be back shortly.',
        ],
    ],

    'fields' => [
        'code' => 'Error :code',
    ],

    'actions' => [
        'home' => 'Back to the CRM',
    ],

];
