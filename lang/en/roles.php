<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'Roles and permissions',
        'model' => 'Role',
        'plural_model' => 'Roles',
    ],

    'sections' => [
        'details' => 'Role details',
        'permissions' => 'Permissions',
    ],

    'fields' => [
        'name' => 'Role name',
        'key' => 'Key',
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'seeded' => 'Built-in',
        'permissions_count' => 'Permissions',
        'users_count' => 'Users',
    ],

    'placeholders' => [
        'key' => 'e.g. regional_manager',
    ],

    'helpers' => [
        'key' => 'Lowercase letters, digits and underscores only. Built-in roles keep their key.',
        'permissions' => 'Choose what holders of this role may do. View permissions are cumulative: "team records" includes own records, and "all records" includes everything.',
    ],

    'validation' => [
        'key_format' => 'The key must start with a lowercase letter and contain only lowercase letters, digits and underscores.',
        'locked' => 'This role cannot be changed or deleted.',
    ],

    'empty' => [
        'heading' => 'No roles',
        'description' => 'Run the onboarding command to create the built-in roles.',
    ],

];
