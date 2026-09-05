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

];
