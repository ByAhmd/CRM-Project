<?php

declare(strict_types=1);

// The audit ledger (سجل التدقيق).
return [

    'navigation' => [
        'label' => 'سجل التدقيق',
        'model' => 'سجل',
        'plural_model' => 'سجل التدقيق',
    ],

    'fields' => [
        'recorded_at' => 'التاريخ والوقت',
        'area' => 'القسم',
        'action' => 'الحدث',
        'subject' => 'السجل المتأثر',
        'causer' => 'بواسطة',
    ],

    'filters' => [
        'date_range' => 'الفترة',
        'from' => 'من',
        'until' => 'إلى',
        'area' => 'القسم',
        'event' => 'الحدث',
        'causer' => 'المستخدم',
    ],

    'actions' => [
        'details' => 'التفاصيل',
        'close' => 'إغلاق',
    ],

    'details' => [
        'heading' => 'تفاصيل الحدث',
        'event' => 'الحدث',
        'subject' => 'السجل',
        'causer' => 'بواسطة',
        'recorded_at' => 'التاريخ والوقت',
        'from_to' => 'من «:old» إلى «:new»',
    ],

    'log_names' => [
        'auth' => 'تسجيل الدخول',
        'user' => 'المستخدمون',
        'role' => 'الأدوار',
        'team' => 'الفرق',
        'settings' => 'الإعدادات',
    ],

    'events' => [
        'auth.login' => 'تسجيل دخول',
        'auth.logout' => 'تسجيل خروج',
        'auth.failed' => 'محاولة دخول فاشلة',
        'auth.password_reset' => 'إعادة تعيين كلمة المرور',
        'user.created' => 'إنشاء مستخدم',
        'user.updated' => 'تعديل مستخدم',
        'user.deleted' => 'حذف مستخدم',
        'user.restored' => 'استعادة مستخدم',
        'user.invited' => 'إرسال دعوة',
        'user.roles_changed' => 'تغيير أدوار المستخدم',
        'role.created' => 'إنشاء دور',
        'role.updated' => 'تعديل دور',
        'role.deleted' => 'حذف دور',
        'role.permissions_changed' => 'تغيير صلاحيات الدور',
        'team.created' => 'إنشاء فريق',
        'team.updated' => 'تعديل فريق',
        'team.deleted' => 'حذف فريق',
        'team.restored' => 'استعادة فريق',
        'settings.updated' => 'تعديل الإعدادات',
        'settings.lookup_created' => 'إضافة عنصر إعدادات',
        'settings.lookup_updated' => 'تعديل عنصر إعدادات',
        'settings.lookup_deleted' => 'حذف عنصر إعدادات',
        'settings.lookup_restored' => 'استعادة عنصر إعدادات',
    ],

    'subject_types' => [
        'User' => 'مستخدم',
        'Role' => 'دور',
        'Team' => 'فريق',
        'LeadSource' => 'مصدر عميل محتمل',
        'LeadStatus' => 'حالة عميل محتمل',
        'Industry' => 'قطاع',
        'Pipeline' => 'مسار بيع',
        'PipelineStage' => 'مرحلة بيع',
        'ActivityType' => 'نوع نشاط',
        'DealCloseReason' => 'سبب إغلاق',
        'Competitor' => 'منافس',
        'Tag' => 'وسم',
        'Product' => 'منتج',
    ],

    'subject' => [
        'deleted' => ':type محذوف (#:id)',
    ],

    'attributes' => [
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'phone' => 'رقم الجوال',
        'locale' => 'اللغة',
        'status' => 'الحالة',
        'team_id' => 'الفريق',
        'name_ar' => 'الاسم بالعربية',
        'name_en' => 'الاسم بالإنجليزية',
        'manager_user_id' => 'مدير الفريق',
        'is_active' => 'نشط',
        'is_default' => 'افتراضي',
        'kind' => 'النوع',
        'color' => 'اللون',
        'icon' => 'الأيقونة',
        'sort' => 'الترتيب',
        'probability' => 'الاحتمالية',
        'pipeline_id' => 'مسار البيع',
        'code' => 'الرمز',
        'unit_price' => 'سعر الوحدة',
        'website' => 'الموقع الإلكتروني',
        'notes' => 'ملاحظات',
        'added' => 'أُضيف',
        'removed' => 'أُزيل',
        'changes' => 'التغييرات',
        'ip' => 'عنوان IP',
        'known_account' => 'حساب معروف',
    ],

    'values' => [
        'yes' => 'نعم',
        'no' => 'لا',
    ],

    'sources' => [
        'system' => 'النظام',
    ],

    'placeholders' => [
        'no_subject' => '—',
        'unknown_account' => 'حساب غير معروف',
    ],

    'empty' => [
        'heading' => 'لا توجد أحداث مسجلة',
        'description' => 'تظهر هنا كل التغييرات والأحداث الأمنية فور حدوثها.',
    ],

];
