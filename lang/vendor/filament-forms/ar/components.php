<?php

declare(strict_types=1);

/**
 * Arabic overrides for keys missing from Filament's own ar/components.php at
 * the installed version (5.7.8). Laravel merges vendor overrides key by key,
 * so only these keys come from here; when upstream adds the Arabic strings,
 * deleting the key here is the whole cleanup.
 */
return [

    'select' => [
        'actions' => [
            'clear' => [
                'label' => 'مسح',
            ],
            'remove_option' => [
                'label' => 'إزالة الخيار',
            ],
        ],
        'search_label' => 'بحث',
    ],

];
