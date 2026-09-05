<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'Industries',
        'model' => 'Industry',
        'plural_model' => 'Industries',
    ],

    'sections' => [
        'details' => 'Industry details',
    ],

    'fields' => [
        'name' => 'Industry name',
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'is_active' => 'Active',
        'sort' => 'Sort order',
    ],

    'helpers' => [
        'is_active' => 'Inactive industries are not offered when classifying accounts and leads.',
        'sort' => 'Display order in lists; lowest first. Rows can also be dragged into order in the table.',
    ],

    'filters' => [
        'is_active' => 'Active',
    ],

    'actions' => [
        'reorder' => 'Reorder',
    ],

    'validation' => [
        'name_unique' => 'An industry with this name already exists.',
    ],

    'empty' => [
        'heading' => 'No industries yet',
        'description' => 'Add the sectors your customers operate in to classify accounts and leads.',
    ],

];
