<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'Products',
        'model' => 'Product',
        'plural_model' => 'Products',
    ],

    'sections' => [
        'details' => 'Product details',
    ],

    'fields' => [
        'name' => 'Product name',
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'code' => 'Code',
        'unit_price' => 'Unit price',
        'is_active' => 'Active',
    ],

    'placeholders' => [
        'code' => 'e.g. PRD-0001',
        'no_code' => 'No code',
    ],

    'helpers' => [
        'code' => 'Optional and unique. Uppercase is recommended; the code is stored in uppercase automatically.',
        'unit_price' => 'The default price when the product is added to a deal, in the organisation currency with at most two decimals.',
        'is_active' => 'Inactive products are not offered when adding deal lines.',
    ],

    'filters' => [
        'is_active' => 'Active',
        'trashed' => 'Deleted',
    ],

    'validation' => [
        'name_unique' => 'A product with this name already exists.',
        'name_unique_trashed' => 'A deleted product has this name; restore it from the "Deleted" filter instead of creating it again.',
        'code_unique' => 'A product with this code already exists.',
        'code_unique_trashed' => 'A deleted product has this code; restore it from the "Deleted" filter instead of creating it again.',
        'unit_price_decimals' => 'The unit price may have at most two decimal places.',
        'unit_price_min' => 'The unit price cannot be negative.',
    ],

    'empty' => [
        'heading' => 'No products yet',
        'description' => 'Add catalogue products so they can be used as line items on deals.',
    ],

];
