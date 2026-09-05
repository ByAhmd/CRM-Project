<?php

declare(strict_types=1);

// Every enum label lives here and nowhere else.
return [

    'user_status' => [
        'active' => 'Active',
        'pending' => 'Invitation pending',
        'disabled' => 'Disabled',
    ],

    'roles' => [
        'super_admin' => 'Super administrator',
        'admin' => 'Administrator',
        'sales_manager' => 'Sales manager',
        'sales_rep' => 'Sales representative',
        'support' => 'Support',
        'read_only' => 'Read only',
    ],

    'visibility_level' => [
        'none' => 'Nothing',
        'own' => 'Own records',
        'team' => 'Team records',
        'all' => 'All records',
    ],

    'lead_status_kind' => [
        'new' => 'New',
        'working' => 'Working',
        'qualified' => 'Qualified',
        'unqualified' => 'Unqualified',
        'converted' => 'Converted',
    ],

    'stage_kind' => [
        'open' => 'Open',
        'won' => 'Won',
        'lost' => 'Lost',
    ],

    'activity_kind' => [
        'call' => 'Call',
        'meeting' => 'Meeting',
        'email' => 'Email',
        'note' => 'Note',
        'task' => 'Task',
        'system' => 'System',
        'other' => 'Other',
    ],

    'close_reason_kind' => [
        'won' => 'Win reason',
        'lost' => 'Loss reason',
    ],

    'badge_color' => [
        'primary' => 'Blue',
        'gray' => 'Gray',
        'success' => 'Green',
        'warning' => 'Orange',
        'danger' => 'Red',
        'info' => 'Sky',
    ],

];
