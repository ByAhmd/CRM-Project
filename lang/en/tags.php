<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'Tags',
        'model' => 'Tag',
        'plural_model' => 'Tags',
    ],

    'sections' => [
        'details' => 'Tag details',
    ],

    'fields' => [
        'name' => 'Tag name',
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'color' => 'Colour',
        'is_active' => 'Active',
    ],

    'helpers' => [
        'color' => 'The badge colour the tag is shown with on records.',
        'is_active' => 'An inactive tag stays on the records that carry it but is not offered when adding tags.',
    ],

    'filters' => [
        'is_active' => 'Active',
    ],

    'validation' => [
        'name_unique' => 'A tag with this name already exists.',
    ],

    'empty' => [
        'heading' => 'No tags yet',
        'description' => 'Create a tag to classify leads, contacts, accounts and deals.',
    ],

];
