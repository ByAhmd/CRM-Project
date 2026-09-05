<?php

declare(strict_types=1);

return [

    'navigation' => [
        'label' => 'أنواع الأنشطة',
        'model' => 'نوع نشاط',
        'plural_model' => 'أنواع الأنشطة',
    ],

    'sections' => [
        'details' => 'بيانات نوع النشاط',
        'appearance' => 'المظهر',
    ],

    'fields' => [
        'name' => 'اسم نوع النشاط',
        'name_ar' => 'الاسم بالعربية',
        'name_en' => 'الاسم بالإنجليزية',
        'kind' => 'الصنف',
        'icon' => 'الأيقونة',
        'color' => 'اللون',
        'is_system' => 'نظامي',
        'is_active' => 'نشط',
        'sort' => 'الترتيب',
    ],

    'placeholders' => [
        'no_icon' => 'بدون أيقونة',
    ],

    'helpers' => [
        'kind' => 'يحدد كيف يُعرض النشاط في الخط الزمني وأي الحقول تنطبق عليه.',
        'kind_locked' => 'لا يمكن تغيير صنف نوع النشاط النظامي.',
        'icon' => 'تُعرض في الخط الزمني؛ الافتراضي أيقونة الصنف.',
        'color' => 'لون الشارة في الخط الزمني والقوائم.',
        'is_active' => 'النوع غير النشط لا يظهر عند تسجيل نشاط.',
        'sort' => 'ترتيب الظهور في القوائم؛ الأصغر أولاً.',
    ],

    'filters' => [
        'is_active' => 'نشط',
        'kind' => 'الصنف',
        'is_system' => 'نظامي',
    ],

    'validation' => [
        'name_unique' => 'يوجد نوع نشاط بهذا الاسم.',
    ],

    'empty' => [
        'heading' => 'لا توجد أنواع أنشطة بعد',
        'description' => 'أنشئ نوع نشاط ليمكن تسجيل الأنشطة عليه.',
    ],

    'options' => [
        'icons' => [
            'OutlinedPhone' => 'هاتف',
            'OutlinedCalendarDays' => 'تقويم',
            'OutlinedEnvelope' => 'ظرف',
            'OutlinedDocumentText' => 'مستند',
            'OutlinedCheckCircle' => 'علامة صح',
            'OutlinedCog6Tooth' => 'ترس',
            'OutlinedClipboardDocumentList' => 'حافظة',
            'OutlinedChatBubbleLeftRight' => 'فقاعات محادثة',
            'OutlinedVideoCamera' => 'كاميرا فيديو',
            'OutlinedUserGroup' => 'أشخاص',
            'OutlinedBuildingOffice' => 'مبنى مكاتب',
            'OutlinedDevicePhoneMobile' => 'هاتف محمول',
            'OutlinedPaperAirplane' => 'طائرة ورقية',
            'OutlinedBell' => 'جرس',
        ],
    ],

];
