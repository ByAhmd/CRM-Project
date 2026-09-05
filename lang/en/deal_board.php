<?php

declare(strict_types=1);

// Deal kanban board (decision D-12).
return [

    'navigation' => 'Deal board',
    'title' => 'Deal board',

    'fields' => [
        'pipeline' => 'Pipeline',
    ],

    'columns' => [
        'total' => 'Total',
        'count' => 'Deals',
        'load_more' => 'Load more',
        'empty' => 'No deals in this stage',
        'recent_closed' => 'Closed in the last :days days',
    ],

    'cards' => [
        'open' => 'Open deal',
    ],

    'notifications' => [
        'moved' => ':deal moved to :stage',
        'refused' => 'The deal could not be moved',
    ],

    'validation' => [
        'stage_outside_pipeline' => 'That stage does not belong to the selected pipeline.',
        'pipeline_inactive' => 'That pipeline is not active.',
    ],

];
