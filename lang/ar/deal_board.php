<?php

declare(strict_types=1);

// لوحة الصفقات (القرار D-12).
return [

    'navigation' => 'لوحة الصفقات',
    'title' => 'لوحة الصفقات',

    'fields' => [
        'pipeline' => 'خط المبيعات',
    ],

    'columns' => [
        'total' => 'الإجمالي',
        'count' => 'الصفقات',
        'load_more' => 'عرض المزيد',
        'empty' => 'لا توجد صفقات في هذه المرحلة',
        'recent_closed' => 'المغلقة خلال آخر :days يوماً',
    ],

    'cards' => [
        'open' => 'فتح الصفقة',
    ],

    'notifications' => [
        'moved' => 'نُقلت :deal إلى :stage',
        'refused' => 'تعذّر نقل الصفقة',
    ],

    'validation' => [
        'stage_outside_pipeline' => 'هذه المرحلة لا تنتمي إلى خط المبيعات المحدد.',
        'pipeline_inactive' => 'خط المبيعات هذا غير نشط.',
    ],

];
