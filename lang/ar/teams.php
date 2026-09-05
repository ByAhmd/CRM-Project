<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'الفرق',
        'model' => 'فريق',
        'plural_model' => 'الفرق',
    ],

    'sections' => [
        'details' => 'بيانات الفريق',
    ],

    'fields' => [
        'name' => 'اسم الفريق',
        'name_ar' => 'الاسم بالعربية',
        'name_en' => 'الاسم بالإنجليزية',
        'manager' => 'مدير الفريق',
        'members_count' => 'عدد الأعضاء',
        'is_active' => 'نشط',
        'sort' => 'الترتيب',
    ],

    'placeholders' => [
        'no_manager' => 'بدون مدير',
    ],

    'helpers' => [
        'manager' => 'اختياري. يُعرض للمعلومة فقط؛ ما يراه المدير يحدده دوره وفريقه.',
        'is_active' => 'الفريق غير النشط لا يظهر عند إسناد المستخدمين.',
        'sort' => 'ترتيب الظهور في القوائم؛ الأصغر أولاً.',
    ],

    'filters' => [
        'is_active' => 'نشط',
        'trashed' => 'المحذوفة',
    ],

    'validation' => [
        'name_unique' => 'يوجد فريق بهذا الاسم.',
    ],

    'empty' => [
        'heading' => 'لا توجد فرق بعد',
        'description' => 'أنشئ فريقاً ثم أسند إليه المستخدمين من صفحة المستخدمين.',
    ],

];
