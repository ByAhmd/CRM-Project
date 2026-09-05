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

    'lead_status_kind' => [
        'new' => 'جديد',
        'working' => 'قيد المتابعة',
        'qualified' => 'مؤهل',
        'unqualified' => 'غير مؤهل',
        'converted' => 'محوّل',
    ],

    'stage_kind' => [
        'open' => 'مفتوحة',
        'won' => 'مكسوبة',
        'lost' => 'خاسرة',
    ],

    'activity_kind' => [
        'call' => 'مكالمة',
        'meeting' => 'اجتماع',
        'email' => 'بريد إلكتروني',
        'note' => 'ملاحظة',
        'task' => 'مهمة',
        'system' => 'النظام',
        'other' => 'أخرى',
    ],

    'close_reason_kind' => [
        'won' => 'سبب الربح',
        'lost' => 'سبب الخسارة',
    ],

    'badge_color' => [
        'primary' => 'أزرق',
        'gray' => 'رمادي',
        'success' => 'أخضر',
        'warning' => 'برتقالي',
        'danger' => 'أحمر',
        'info' => 'سماوي',
    ],

];
