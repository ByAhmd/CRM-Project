<?php

declare(strict_types=1);

return [

    'general' => [
        'navigation' => 'General settings',
        'title' => 'General settings',
        'sections' => [
            'organisation' => 'Organisation',
        ],
        'fields' => [
            'organisation_name' => 'Organisation name',
            'currency' => 'Currency',
            'timezone' => 'Time zone',
            'week_starts_on' => 'Week starts on',
        ],
        'helpers' => [
            'currency' => 'Used for every amount on deals and reports.',
            'timezone' => 'Dates and times are displayed in this time zone.',
        ],
    ],

    'actions' => [
        'save' => 'Save',
    ],

    'notifications' => [
        'saved' => 'Settings saved',
    ],

    'currencies' => [
        'SAR' => 'Saudi riyal (SAR)',
        'AED' => 'UAE dirham (AED)',
        'KWD' => 'Kuwaiti dinar (KWD)',
        'BHD' => 'Bahraini dinar (BHD)',
        'QAR' => 'Qatari riyal (QAR)',
        'OMR' => 'Omani rial (OMR)',
        'EGP' => 'Egyptian pound (EGP)',
        'JOD' => 'Jordanian dinar (JOD)',
        'USD' => 'US dollar (USD)',
        'EUR' => 'Euro (EUR)',
    ],

    'days' => [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ],

];
