<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'جهات الاتصال',
        'model' => 'جهة اتصال',
        'plural_model' => 'جهات الاتصال',
    ],

    'sections' => [
        'details' => 'البيانات الأساسية',
        'contact' => 'بيانات التواصل',
        'ownership' => 'المسؤول والوسوم',
        'notes' => 'ملاحظات',
    ],

    'fields' => [
        'name' => 'الاسم',
        'first_name' => 'الاسم الأول',
        'last_name' => 'اسم العائلة',
        'account' => 'الشركة',
        'job_title' => 'المسمى الوظيفي',
        'department' => 'القسم',
        'email' => 'البريد الإلكتروني',
        'mobile' => 'الجوال',
        'phone' => 'الهاتف',
        'preferred_locale' => 'لغة المراسلة',
        'linkedin_url' => 'رابط LinkedIn',
        'is_primary' => 'جهة الاتصال الرئيسية',
        'owner' => 'المسؤول',
        'description' => 'ملاحظات',
        'created_by' => 'أنشأه',
        'created_at' => 'تاريخ الإنشاء',
        'updated_at' => 'آخر تعديل',
    ],

    'helpers' => [
        'account' => 'تنتمي جهة الاتصال إلى شركة واحدة على الأكثر.',
        'is_primary' => 'جهة اتصال رئيسية واحدة لكل شركة؛ تفعيلها هنا يلغيها عن غيرها.',
        'preferred_locale' => 'تُرسل الرسائل إلى جهة الاتصال بهذه اللغة.',
    ],

    'filters' => [
        'account' => 'الشركة',
        'owner' => 'المسؤول',
        'is_primary' => 'جهة اتصال رئيسية',
        'trashed' => 'المحذوفة',
    ],

    'validation' => [
        'account_out_of_reach' => 'هذه الشركة غير موجودة أو خارج نطاق صلاحياتك.',
    ],

    'empty' => [
        'heading' => 'لا توجد جهات اتصال بعد',
        'description' => 'أضف أول جهة اتصال أو أنشئها من صفحة الشركة.',
    ],

];
