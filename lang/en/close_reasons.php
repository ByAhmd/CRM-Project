<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'Close reasons',
        'model' => 'Close reason',
        'plural_model' => 'Close reasons',
    ],

    'sections' => [
        'details' => 'Close reason details',
    ],

    'fields' => [
        'kind' => 'Kind',
        'name' => 'Reason name',
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'is_active' => 'Active',
        'sort' => 'Sort order',
    ],

    'placeholders' => [
        'name_ar' => 'The reason in Arabic, e.g. Competitor',
        'name_en' => 'e.g. Competitor',
    ],

    'helpers' => [
        'kind' => 'Whether this reason explains a won deal or a lost one.',
        'is_active' => 'Inactive reasons are not offered when closing deals. A reason that closed deals use cannot be deleted; deactivate it instead.',
        'sort' => 'Display order in lists; lowest first. Rows can also be dragged in the table.',
    ],

    'filters' => [
        'kind' => 'Kind',
        'is_active' => 'Active',
    ],

    'validation' => [
        'name_unique' => 'A reason with this name already exists for this kind.',
    ],

    'empty' => [
        'heading' => 'No close reasons yet',
        'description' => 'Add the win and loss reasons reps choose when closing deals.',
    ],

];
