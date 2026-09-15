<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'Teams',
        'model' => 'Team',
        'plural_model' => 'Teams',
    ],

    'sections' => [
        'details' => 'Team details',
    ],

    'fields' => [
        'name' => 'Team name',
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'manager' => 'Team manager',
        'members_count' => 'Members',
        'is_active' => 'Active',
        'sort' => 'Sort order',
    ],

    'placeholders' => [
        'no_manager' => 'No manager',
    ],

    'helpers' => [
        'manager' => 'Optional. Chosen among the active members of this team; what a manager sees is decided by their role and team.',
        'is_active' => 'Inactive teams are not offered when assigning users.',
        'sort' => 'Display order in lists; lowest first.',
    ],

    'filters' => [
        'is_active' => 'Active',
        'trashed' => 'Deleted',
    ],

    'validation' => [
        'name_unique' => 'A team with this name already exists.',
        'name_ar_unique_trashed' => 'A deleted team has this Arabic name. Restore it from the deleted teams instead of creating it again.',
        'name_en_unique_trashed' => 'A deleted team has this English name. Restore it from the deleted teams instead of creating it again.',
    ],

    'empty' => [
        'heading' => 'No teams yet',
        'description' => 'Create a team, then assign users to it from the Users page.',
    ],

];
