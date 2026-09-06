<?php

declare(strict_types=1);

// استيراد ملفات CSV وسجلّه (الوحدة 18، القراران D-1 وD-4).
return [

    'navigation' => [
        'label' => 'عمليات الاستيراد',
        'model' => 'عملية استيراد',
        'plural_model' => 'عمليات الاستيراد',
    ],

    'sections' => [
        'summary' => 'عملية الاستيراد',
        'failed_rows' => 'الصفوف المرفوضة',
    ],

    'fields' => [
        'file_name' => 'الملف',
        'entity' => 'السجلات',
        'user' => 'استورده',
        'total_rows' => 'الصفوف',
        'processed_rows' => 'المعالَجة',
        'successful_rows' => 'المستوردة',
        'failed_rows' => 'المرفوضة',
        'status' => 'الحالة',
        'created_at' => 'البدء',
        'completed_at' => 'الاكتمال',
        'duplicate_strategy' => 'السجلات المكررة',
        'row_data' => 'الصف',
        'error' => 'السبب',
    ],

    'columns' => [
        'lead' => [
            'first_name' => 'الاسم الأول',
            'last_name' => 'اسم العائلة',
            'company_name' => 'الشركة',
            'job_title' => 'المسمى الوظيفي',
            'email' => 'البريد الإلكتروني',
            'phone' => 'الهاتف',
            'website' => 'الموقع الإلكتروني',
            'address_line' => 'العنوان',
            'city' => 'المدينة',
            'region' => 'المنطقة',
            'country' => 'الدولة (الرمز)',
            'postal_code' => 'الرمز البريدي',
            'source' => 'المصدر',
            'status' => 'الحالة',
            'priority' => 'الأولوية',
            'owner' => 'المالك (البريد الإلكتروني)',
            'tags' => 'الوسوم',
            'description' => 'ملاحظات',
        ],
        'contact' => [
            'first_name' => 'الاسم الأول',
            'last_name' => 'اسم العائلة',
            'account' => 'الشركة',
            'job_title' => 'المسمى الوظيفي',
            'department' => 'القسم',
            'email' => 'البريد الإلكتروني',
            'mobile' => 'الجوال',
            'phone' => 'الهاتف',
            'preferred_locale' => 'لغة المراسلة',
            'linkedin_url' => 'رابط لينكدإن',
            'is_primary' => 'جهة الاتصال الرئيسية',
            'address_line' => 'العنوان',
            'city' => 'المدينة',
            'region' => 'المنطقة',
            'country' => 'الدولة (الرمز)',
            'postal_code' => 'الرمز البريدي',
            'owner' => 'المالك (البريد الإلكتروني)',
            'tags' => 'الوسوم',
            'description' => 'ملاحظات',
        ],
        'account' => [
            'name' => 'اسم الشركة',
            'type' => 'النوع',
            'industry' => 'القطاع',
            'size' => 'حجم الشركة',
            'website' => 'الموقع الإلكتروني',
            'email' => 'البريد الإلكتروني',
            'phone' => 'الهاتف',
            'address_line' => 'العنوان',
            'city' => 'المدينة',
            'region' => 'المنطقة',
            'country' => 'الدولة (الرمز)',
            'postal_code' => 'الرمز البريدي',
            'parent' => 'الشركة الأم',
            'owner' => 'المالك (البريد الإلكتروني)',
            'tags' => 'الوسوم',
            'description' => 'ملاحظات',
        ],
        'deal' => [
            'title' => 'العنوان',
            'account' => 'الحساب',
            'contact' => 'جهة الاتصال الرئيسية',
            'pipeline' => 'خط المبيعات',
            'stage' => 'المرحلة',
            'amount' => 'المبلغ',
            'probability' => 'الاحتمالية (%)',
            'expected_close_date' => 'تاريخ الإغلاق المتوقع',
            'forecast_category' => 'فئة التوقع',
            'source' => 'المصدر',
            'owner' => 'المالك (البريد الإلكتروني)',
            'tags' => 'الوسوم',
            'description' => 'ملاحظات',
        ],
    ],

    'options' => [
        'duplicate_strategy' => [
            'skip' => 'تخطي المكرر',
            'update' => 'تحديث المكرر',
        ],
    ],

    'helpers' => [
        'duplicate_strategy' => 'السجل المكرر هو سجل موجود تراه بالبريد الإلكتروني أو الهاتف نفسه (الحسابات: الاسم أو البريد نفسه؛ الصفقات: العنوان نفسه على الحساب نفسه).',
        'failed_rows' => 'تُعرض هنا أول :limit صفًا مرفوضًا؛ ويحوي التنزيل كل الصفوف المرفوضة مع سببها.',
    ],

    'statuses' => [
        'completed' => 'مكتملة',
        'failed' => 'فاشلة',
        'processing' => 'قيد المعالجة',
    ],

    'notifications' => [
        'completed' => 'استُورد :successful صفًا، ورُفض :failed صفًا.',
    ],

    'validation' => [
        'duplicate' => 'مكرر للسجل رقم :id.',
        'update_forbidden' => 'لا تملك صلاحية تعديل السجل رقم :id.',
        'create_forbidden' => 'لا تملك صلاحية إنشاء سجلات من هذا النوع؛ لم يُستورد الصف.',
        'converted_status' => 'حالة «محوّل» تُضبط بتحويل العميل المحتمل لا بالاستيراد.',
        'owner_out_of_reach' => 'المالك ":value" ليس مستخدمًا يمكنك الإسناد إليه.',
        'unknown_lookup' => 'قيمة غير معروفة ":value".',
        'account_not_found' => 'الحساب ":value" غير موجود أو خارج نطاق صلاحيتك.',
        'stage_not_open' => 'المرحلة ":value" مرحلة مغلقة؛ يجب أن تبدأ الصفقة في مرحلة مفتوحة.',
        'stage_outside_pipeline' => 'المرحلة ":value" تتبع خط مبيعات آخر.',
        'pipeline_unavailable' => 'لا يوجد خط مبيعات افتراضي بمرحلة مفتوحة؛ راجع المسؤول.',
    ],

    'actions' => [
        'import' => 'استيراد :label',
        'download_failed_rows' => 'تنزيل الصفوف المرفوضة',
        'view' => 'عرض',
    ],

    'filters' => [
        'entity' => 'السجلات',
        'from' => 'من',
        'until' => 'إلى',
    ],

    'empty' => [
        'heading' => 'لا توجد عمليات استيراد بعد',
        'description' => 'استورد ملف CSV من صفحة قائمة وستظهر العملية هنا.',
    ],

];
