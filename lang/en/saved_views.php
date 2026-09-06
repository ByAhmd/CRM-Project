<?php

declare(strict_types=1);

// Saved table views (decision A-8).
return [

    'actions' => [
        'views' => 'Views',
        'save' => 'Save view',
        'save_heading' => 'Save the current view',
        'save_submit' => 'Save',
        'manage' => 'Manage views',
        'manage_heading' => 'Manage saved views',
        'delete' => 'Delete',
        'set_default' => 'Set as default',
        'clear' => 'Clear view',
    ],

    'fields' => [
        'name' => 'View name',
        'is_shared' => 'Share with everyone',
        'is_default' => 'Open this view by default',
        'view' => 'Saved view',
    ],

    'helpers' => [
        'is_shared' => 'A shared view appears in everyone\'s list; each person still sees only the records within their own reach.',
        'is_default' => 'The list opens with this view whenever no other filters are active.',
        'manage' => 'Choose a view, then set it as your default or delete it.',
    ],

    'labels' => [
        'shared_suffix' => '(shared)',
        'default_suffix' => '(default)',
        'mine' => 'My views',
        'shared' => 'Shared views',
        'owned_by' => ':name — :owner',
    ],

    'validation' => [
        'name_taken' => 'You already have a view with this name on this list.',
        'name_too_long' => 'The view name may not be longer than :max characters.',
        'search_too_long' => 'The search text is too long to save (at most :max characters).',
        'sort_column_too_long' => 'The sort column name is too long to save (at most :max characters).',
        'share_forbidden' => 'You are not allowed to share views.',
        'update_forbidden' => 'Only the owner of a view can change it.',
        'delete_forbidden' => 'You are not allowed to delete this view.',
    ],

    'notifications' => [
        'saved' => 'View ":name" saved',
        'applied' => 'View ":name" applied',
        'deleted' => 'View ":name" deleted',
        'default_set' => 'View ":name" is now your default',
    ],

    'empty' => 'No saved views yet',

];
