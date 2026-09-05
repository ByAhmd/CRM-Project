<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'Lead sources',
        'model' => 'Lead source',
        'plural_model' => 'Lead sources',
    ],

    'sections' => [
        'details' => 'Source details',
    ],

    'fields' => [
        'name' => 'Source',
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'is_active' => 'Active',
        'sort' => 'Sort order',
    ],

    'helpers' => [
        'is_active' => 'Inactive sources are not offered when creating a lead; existing leads keep their source.',
        'sort' => 'Display order in lists; lowest first. Rows can also be dragged in the table.',
    ],

    'filters' => [
        'is_active' => 'Active',
    ],

    'actions' => [
        'reorder' => 'Reorder',
    ],

    'validation' => [
        'name_unique' => 'A source with this name already exists.',
    ],

    'empty' => [
        'heading' => 'No lead sources yet',
        'description' => 'Add the channels leads come from, such as the website or referrals.',
    ],

];
