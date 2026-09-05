<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'الحسابات',
        'model' => 'حساب',
        'plural_model' => 'الحسابات',
    ],

    'tabs' => [
        'all' => 'الكل',
    ],

    'sections' => [
        'details' => 'بيانات الحساب',
        'contact' => 'بيانات التواصل',
        'ownership' => 'المسؤول والوسوم',
        'notes' => 'ملاحظات',
    ],

    'fields' => [
        'name' => 'اسم الشركة',
        'type' => 'النوع',
        'industry' => 'القطاع',
        'size' => 'حجم الشركة',
        'parent' => 'الشركة الأم',
        'website' => 'الموقع الإلكتروني',
        'email' => 'البريد الإلكتروني',
        'phone' => 'الهاتف',
        'owner' => 'المسؤول',
        'customer_since' => 'عميل منذ',
        'contacts_count' => 'جهات الاتصال',
        'description' => 'ملاحظات',
        'created_by' => 'أنشأه',
        'created_at' => 'تاريخ الإنشاء',
        'updated_at' => 'آخر تعديل',
    ],

    'placeholders' => [
        'name' => 'مثال: شركة الأفق للتجارة',
    ],

    'helpers' => [
        'type' => 'يتحول العميل المحتمل إلى عميل تلقائياً عند كسب أول فرصة بيعية.',
        'parent' => 'اختياري: الشركة التي يتبع لها هذا الحساب.',
    ],

    'filters' => [
        'type' => 'النوع',
        'industry' => 'القطاع',
        'owner' => 'المسؤول',
        'trashed' => 'المحذوفة',
    ],

    'empty' => [
        'heading' => 'لا توجد حسابات بعد',
        'description' => 'أضف أول شركة أو حوّل عميلاً محتملاً.',
    ],

];
