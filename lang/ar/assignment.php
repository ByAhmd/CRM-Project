<?php

declare(strict_types=1);

// Ownership and reassignment (decision D-4).
return [

    'fields' => [
        'owner' => 'المسؤول',
    ],

    'helpers' => [
        'owner' => 'تظهر فقط الأسماء التي يحق لك الإسناد إليها.',
    ],

    'placeholders' => [
        'unassigned' => 'غير مُسند',
    ],

    'actions' => [
        'assign' => 'إسناد',
        'assign_heading' => 'إسناد إلى مستخدم',
        'assign_submit' => 'إسناد',
    ],

    'validation' => [
        'outside_reach' => 'لا يمكنك الإسناد إلى هذا المستخدم لأنه خارج نطاق صلاحياتك.',
    ],

    'notifications' => [
        'assigned' => 'تم الإسناد إلى :name',
        'unassigned' => 'تمت إزالة الإسناد',
        'bulk_done' => '{0} لم يُسند أي سجل|{1} تم إسناد سجل واحد|{2} تم إسناد سجلين|[3,10] تم إسناد :count سجلات|[11,99] تم إسناد :count سجلاً|[100,*] تم إسناد :count سجل',
        'title' => 'تم إسناد :entity إليك',
        'body' => 'أسند :by إليك :entity «:record».',
        'greeting' => 'مرحباً :name،',
        'open' => 'فتح السجل',
    ],

    'entities' => [
        'account' => 'حساب',
        'contact' => 'جهة اتصال',
        'lead' => 'عميل محتمل',
        'deal' => 'صفقة',
        'task' => 'مهمة',
        'activity' => 'نشاط',
    ],

];
