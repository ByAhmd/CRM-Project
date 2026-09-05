<?php

declare(strict_types=1);

// Duplicate detection and merging (decision A-11).
return [

    'actions' => [
        'merge' => 'دمج المكررات',
        'heading' => 'دمج سجلين',
        'description' => 'يُحتفظ بالسجل الذي تختاره وتُنقل إليه بيانات السجل الآخر وعلاقاته، ثم يُحذف الآخر. يمكن استعادته من المحذوفات.',
        'submit' => 'دمج',
    ],

    'fields' => [
        'keep' => 'السجل الذي يُحتفظ به',
    ],

    'helpers' => [
        'keep' => 'تُملأ الحقول الفارغة في هذا السجل من السجل الآخر.',
    ],

    'validation' => [
        'exactly_two' => 'اختر سجلين اثنين بالضبط للدمج.',
    ],

    'warnings' => [
        'possible_duplicates' => 'قد يكون هذا السجل مكرراً لـ:',
        'lead' => 'العميل المحتمل :name',
        'contact' => 'جهة الاتصال الحالية :name',
    ],

    'notifications' => [
        'done' => 'تم الدمج',
    ],

];
