<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'Competitors',
        'model' => 'Competitor',
        'plural_model' => 'Competitors',
    ],

    'sections' => [
        'details' => 'Competitor details',
    ],

    'fields' => [
        'name' => 'Competitor name',
        'website' => 'Website',
        'notes' => 'Notes',
        'is_active' => 'Active',
    ],

    'placeholders' => [
        'name' => 'e.g. Competitor Ltd',
        'website' => 'https://example.com',
        'notes' => 'Strengths, weaknesses, pricing and anything that helps the sales team.',
        'no_website' => 'No website',
    ],

    'helpers' => [
        'website' => 'Optional. A full link starting with http or https.',
        'notes' => 'Optional. Internal notes never shown to customers.',
        'is_active' => 'Inactive competitors are not offered when choosing competitors on deals.',
    ],

    'filters' => [
        'is_active' => 'Active',
        'trashed' => 'Deleted',
    ],

    'validation' => [
        'name_unique' => 'A competitor with this name already exists.',
        'name_unique_trashed' => 'A deleted competitor has this name; restore it from the "Deleted" filter instead of creating it again.',
        'website_url' => 'The website must be a valid URL.',
    ],

    'empty' => [
        'heading' => 'No competitors yet',
        'description' => 'Add the competitors you meet on deals to track why they are won and lost.',
    ],

];
