<?php

declare(strict_types=1);

// Every enum label lives here and nowhere else.
return [

    'user_status' => [
        'active' => 'نشط',
        'pending' => 'بانتظار قبول الدعوة',
        'disabled' => 'موقوف',
    ],

    'roles' => [
        'super_admin' => 'مدير النظام الأعلى',
        'admin' => 'مدير النظام',
        'sales_manager' => 'مدير المبيعات',
        'sales_rep' => 'مندوب مبيعات',
        'support' => 'دعم',
        'read_only' => 'قراءة فقط',
    ],

    'visibility_level' => [
        'none' => 'لا شيء',
        'own' => 'سجلاته فقط',
        'team' => 'سجلات فريقه',
        'all' => 'كل السجلات',
    ],

];
