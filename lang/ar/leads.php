<?php

declare(strict_types=1);

// العملاء المحتملون (القرار D-7).
return [

    'navigation' => [
        'label' => 'العملاء المحتملون',
        'model' => 'عميل محتمل',
        'plural_model' => 'العملاء المحتملون',
    ],

    'tabs' => [
        'open' => 'المفتوحون',
        'qualified' => 'المؤهلون',
        'unqualified' => 'غير المؤهلين',
        'converted' => 'المحوّلون',
        'all' => 'الكل',
    ],

    'sections' => [
        'person' => 'بيانات العميل المحتمل',
        'classification' => 'الحالة والتقييم',
        'contact' => 'بيانات التواصل',
        'ownership' => 'المالك والوسوم',
        'conversion' => 'التحويل',
        'status_history' => 'سجل الحالات',
        'notes' => 'ملاحظات',
    ],

    'fields' => [
        'name' => 'الاسم',
        'first_name' => 'الاسم الأول',
        'last_name' => 'اسم العائلة',
        'company_name' => 'الشركة',
        'job_title' => 'المسمى الوظيفي',
        'email' => 'البريد الإلكتروني',
        'phone' => 'الهاتف',
        'website' => 'الموقع الإلكتروني',
        'city' => 'المدينة',
        'source' => 'المصدر',
        'lead_source_id' => 'المصدر',
        'status' => 'الحالة',
        'status_note' => 'ملاحظة',
        'priority' => 'الأولوية',
        'score' => 'التقييم',
        'score_override' => 'تقييم يدوي',
        'owner' => 'المالك',
        'qualified_at' => 'تاريخ التأهيل',
        'qualified_by' => 'أهّله',
        'last_activity_at' => 'آخر نشاط',
        'converted_at' => 'تاريخ التحويل',
        'converted_by' => 'حوّله',
        'converted_account' => 'الحساب',
        'converted_contact' => 'جهة الاتصال',
        'description' => 'ملاحظات',
        'created_by' => 'أنشأه',
        'created_at' => 'تاريخ الإنشاء',
        'updated_at' => 'آخر تحديث',
    ],

    'helpers' => [
        'initial_status' => 'الحالة التي يبدأ بها العميل المحتمل. التغييرات اللاحقة تتم عبر إجراء «تغيير الحالة» حتى تُسجَّل.',
        'status_readonly' => 'استخدم إجراء «تغيير الحالة» لنقل هذا العميل المحتمل.',
        'score_override' => 'اتركه فارغاً لاستخدام التقييم المحسوب (0–100).',
        'status_note' => 'مطلوبة عند نقل العميل المحتمل إلى حالة مؤهلة.',
    ],

    'filters' => [
        'status' => 'الحالة',
        'source' => 'المصدر',
        'priority' => 'الأولوية',
        'owner' => 'المالك',
        'trashed' => 'المحذوفون',
    ],

    'actions' => [
        'change_status' => 'تغيير الحالة',
        'change_status_heading' => 'تغيير حالة العميل المحتمل',
        'change_status_submit' => 'تغيير الحالة',
    ],

    'notifications' => [
        'status_changed' => 'تغيّرت الحالة إلى :status',
        'bulk_status_changed' => 'تم تحديث :changed من العملاء المحتملين وتخطي :failed',
    ],

    'history' => [
        'changed_at' => 'التاريخ',
        'from' => 'من',
        'to' => 'إلى',
        'by' => 'بواسطة',
        'notes' => 'ملاحظة',
    ],

    'validation' => [
        'already_converted' => 'تم تحويل هذا العميل المحتمل مسبقاً ولا يمكن تغييره.',
        'inactive_status' => 'هذه الحالة غير نشطة ولا يمكن استخدامها.',
        'converted_status_reserved' => 'تُضبط حالة «محوّل» عبر تحويل العميل المحتمل وليس يدوياً.',
        'qualification_note_required' => 'ملاحظة التأهيل مطلوبة لوضع العميل المحتمل في حالة مؤهلة.',
    ],

    'empty' => [
        'heading' => 'لا يوجد عملاء محتملون بعد',
        'description' => 'أضف أول عميل محتمل لبدء العمل.',
    ],

];
