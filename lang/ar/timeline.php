<?php

declare(strict_types=1);

// الخط الزمني للسجل: تسلسل زمني واحد لكل عميل محتمل وجهة اتصال وحساب وصفقة (الوحدة 12، القرار D-13).
return [

    'section' => 'الخط الزمني',

    'kinds' => [
        'activity' => 'نشاط',
        'note' => 'ملاحظة',
        'task' => 'مهمة',
        'status_change' => 'تغيير الحالة',
        'stage_change' => 'تغيير المرحلة',
        'attachment' => 'مرفق',
        'assignment' => 'إسناد',
        'conversion' => 'تحويل',
        'lifecycle' => 'دورة الحياة',
        'audit' => 'سجل التدقيق',
    ],

    'titles' => [
        'note_added' => 'أضاف :author ملاحظة',
        'task_created' => 'أُنشئت المهمة: :title',
        'task_completed' => 'اكتملت المهمة: :title',
        'status_changed' => 'تغيّرت الحالة: :from → :to',
        'stage_changed' => 'تغيّرت المرحلة: :from → :to',
        'attachment_uploaded' => 'أُرفق الملف: :name',
        'assigned' => 'تغيّر المالك: :from → :to',
        'unassigned' => 'أُزيل المالك',
        'merged' => 'تم الدمج مع :label',
        'converted' => 'تم تحويل العميل المحتمل',
        'converted_from' => 'أُنشئ بتحويل العميل المحتمل :label',
        'became_customer' => 'أصبح عميلاً',
        'created' => 'أُنشئ السجل',
        'restored' => 'استُعيد السجل',
        'deleted' => 'حُذف السجل',
        'qualified' => 'تم تأهيل العميل المحتمل',
        'won' => 'كُسبت الصفقة',
        'lost' => 'خُسرت الصفقة',
        'reopened' => 'أُعيد فتح الصفقة',
    ],

    'fields' => [
        'by' => 'بواسطة',
        'at' => 'في',
        'duration' => 'المدة في المرحلة السابقة',
        'pinned' => 'مثبتة',
        'size' => 'الحجم',
        'priority' => 'الأولوية',
        'due' => 'الاستحقاق',
        'outcome' => 'النتيجة',
        'lead' => 'العميل المحتمل',
        'account' => 'الحساب',
        'contact' => 'جهة الاتصال',
        'deal' => 'الصفقة',
        'amount' => 'المبلغ',
        'close_reason' => 'سبب الإغلاق',
    ],

    'values' => [
        'yes' => 'نعم',
    ],

    'formats' => [
        'meta' => ':label: :value',
    ],

    'actions' => [
        'load_more' => 'عرض المزيد',
    ],

    'empty' => [
        'heading' => 'لا شيء في الخط الزمني بعد',
        'description' => 'ستظهر هنا الأنشطة والملاحظات والمهام والملفات والتغييرات فور حدوثها.',
    ],

    'hints' => [
        'capped' => 'يُعرض آخر :count عنصراً. تبقى العناصر الأقدم في أقسام الأنشطة والملاحظات والسجل الخاصة بالسجل.',
        'loading' => 'جارٍ تحميل الخط الزمني…',
    ],

];
