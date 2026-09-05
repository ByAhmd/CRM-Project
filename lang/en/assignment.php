<?php

declare(strict_types=1);

// Ownership and reassignment (decision D-4).
return [

    'fields' => [
        'owner' => 'Owner',
    ],

    'helpers' => [
        'owner' => 'Only the people you are allowed to assign to are listed.',
    ],

    'placeholders' => [
        'unassigned' => 'Unassigned',
    ],

    'actions' => [
        'assign' => 'Assign',
        'assign_heading' => 'Assign to a user',
        'assign_submit' => 'Assign',
    ],

    'validation' => [
        'outside_reach' => 'You cannot assign to this user: they are outside your reach.',
    ],

    'notifications' => [
        'assigned' => 'Assigned to :name',
        'unassigned' => 'Assignment removed',
        'bulk_done' => ':count records assigned',
        'title' => 'A :entity was assigned to you',
        'body' => ':by assigned the :entity ":record" to you.',
        'greeting' => 'Hello :name,',
        'open' => 'Open record',
    ],

    'entities' => [
        'account' => 'account',
        'contact' => 'contact',
        'lead' => 'lead',
        'deal' => 'deal',
        'task' => 'task',
        'activity' => 'activity',
    ],

];
