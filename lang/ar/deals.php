<?php

declare(strict_types=1);

// الصفقات (القراران D-6 وD-8).
return [

    'navigation' => [
        'label' => 'الصفقات',
        'model' => 'صفقة',
        'plural_model' => 'الصفقات',
    ],

    'tabs' => [
        'open' => 'المفتوحة',
        'won' => 'المكسوبة',
        'lost' => 'المفقودة',
        'all' => 'الكل',
    ],

    'sections' => [
        'details' => 'بيانات الصفقة',
        'value' => 'القيمة والتوقعات',
        'close' => 'الإغلاق',
        'stage_history' => 'سجل المراحل',
        'notes' => 'ملاحظات',
        'contacts' => 'جهات الاتصال',
        'competitors' => 'المنافسون',
        'pipeline' => 'مسار المبيعات والمرحلة',
        'summary' => 'الملخص',
        'line_items' => 'بنود الصفقة',
    ],

    'fields' => [
        'title' => 'عنوان الصفقة',
        'account' => 'الحساب',
        'contact' => 'جهة الاتصال الرئيسية',
        'pipeline' => 'مسار المبيعات',
        'stage' => 'المرحلة',
        'owner' => 'المسؤول',
        'status' => 'الحالة',
        'amount' => 'المبلغ',
        'probability' => 'الاحتمالية (%)',
        'effective_probability' => 'الاحتمالية الفعلية (%)',
        'weighted_amount' => 'المبلغ المرجّح',
        'expected_close_date' => 'تاريخ الإغلاق المتوقع',
        'forecast_category' => 'فئة التوقع',
        'source' => 'المصدر',
        'lead' => 'العميل المحتمل الأصلي',
        'close_reason' => 'سبب الإغلاق',
        'lost_notes' => 'ملاحظات الخسارة',
        'won_at' => 'تاريخ الفوز',
        'lost_at' => 'تاريخ الخسارة',
        'last_activity_at' => 'آخر نشاط',
        'description' => 'ملاحظات',
        'created_by' => 'أنشأها',
        'created_at' => 'تاريخ الإنشاء',
        'updated_at' => 'آخر تحديث',
        'stage_note' => 'ملاحظة',
        'products_count' => 'البنود',
        'product' => 'المنتج',
        'line_description' => 'الوصف',
        'quantity' => 'الكمية',
        'unit_price' => 'سعر الوحدة',
        'discount_percent' => 'الخصم (%)',
        'line_total' => 'إجمالي البند',
        'role' => 'الدور',
        'is_winner' => 'فاز بالصفقة',
        'competitor_notes' => 'ملاحظات',
        'closed_by' => 'أغلقها',
        'total' => 'الإجمالي',
    ],

    'helpers' => [
        'amount' => 'يُحسب من البنود عند وجودها؛ وإلا يُدخل يدوياً.',
        'probability' => 'اتركه فارغاً لاستخدام احتمالية المرحلة.',
        'stage_readonly' => 'استخدم إجراءات «تغيير المرحلة» و«تسجيل الفوز» و«تسجيل الخسارة» لنقل هذه الصفقة.',
        'initial_stage' => 'المرحلة التي تبدأ بها الصفقة. التغييرات اللاحقة تتم عبر إجراء «تغيير المرحلة» حتى تُسجَّل.',
        'lost_notes' => 'ما الذي حدث وما الذي يمكن تعلّمه منه.',
    ],

    'filters' => [
        'pipeline' => 'مسار المبيعات',
        'stage' => 'المرحلة',
        'owner' => 'المسؤول',
        'status' => 'الحالة',
        'forecast_category' => 'فئة التوقع',
        'expected_close_from' => 'الإغلاق المتوقع من',
        'expected_close_until' => 'الإغلاق المتوقع حتى',
        'trashed' => 'المحذوفة',
    ],

    'actions' => [
        'change_stage' => 'تغيير المرحلة',
        'change_stage_heading' => 'تغيير مرحلة الصفقة',
        'change_stage_submit' => 'تغيير المرحلة',
        'mark_won' => 'تسجيل الفوز',
        'mark_won_heading' => 'تسجيل الفوز بالصفقة',
        'mark_won_submit' => 'تسجيل الفوز',
        'mark_lost' => 'تسجيل الخسارة',
        'mark_lost_heading' => 'تسجيل خسارة الصفقة',
        'mark_lost_submit' => 'تسجيل الخسارة',
        'reopen' => 'إعادة فتح',
        'reopen_heading' => 'إعادة فتح الصفقة',
        'reopen_submit' => 'إعادة فتح',
        'attach_contact' => 'إضافة جهة اتصال',
        'attach_competitor' => 'إضافة منافس',
        'add_line' => 'إضافة بند',
    ],

    'notifications' => [
        'stage_changed' => 'تغيّرت المرحلة إلى :stage',
        'won' => 'سُجّل الفوز بالصفقة',
        'lost' => 'سُجّلت خسارة الصفقة',
        'reopened' => 'أُعيد فتح الصفقة',
    ],

    'history' => [
        'changed_at' => 'التاريخ',
        'from' => 'من',
        'to' => 'إلى',
        'by' => 'بواسطة',
        'notes' => 'ملاحظة',
        'duration' => 'المدة في المرحلة السابقة',
        'days' => '{0} :count يوم|{1} يوم واحد|{2} يومان|[3,10] :count أيام|[11,*] :count يوماً',
        'hours' => '{0} :count ساعة|{1} ساعة واحدة|{2} ساعتان|[3,10] :count ساعات|[11,*] :count ساعة',
        'minutes' => '{0} :count دقيقة|{1} دقيقة واحدة|{2} دقيقتان|[3,10] :count دقائق|[11,*] :count دقيقة',
    ],

    'validation' => [
        'stage_outside_pipeline' => 'هذه المرحلة لا تنتمي إلى مسار مبيعات الصفقة.',
        'initial_stage_must_be_open' => 'يجب أن تبدأ الصفقة في مرحلة مفتوحة. سجّل الفوز بها أو خسارتها لاحقاً عبر إجراءي «تسجيل الفوز» و«تسجيل الخسارة».',
        'already_closed' => 'هذه الصفقة مغلقة. أعد فتحها قبل تغيير مرحلتها.',
        'not_closed' => 'لا يمكن إعادة فتح إلا صفقة مكسوبة أو مفقودة.',
        'close_reason_required' => 'سبب الإغلاق مطلوب لتسجيل الفوز بالصفقة أو خسارتها.',
        'close_reason_kind_mismatch' => 'سبب الإغلاق لا يطابق النتيجة: اختر سبب فوز للصفقة المكسوبة وسبب خسارة للصفقة المفقودة.',
        'pipeline_has_no_closed_stage' => 'مسار مبيعات الصفقة لا يحتوي على مرحلة فوز أو خسارة.',
        'pipeline_has_no_open_stage' => 'مسار مبيعات الصفقة لا يحتوي على مرحلة مفتوحة لإعادة الفتح إليها.',
        'line_total_too_large' => 'لا يمكن أن يتجاوز إجمالي البند :max. قلّل الكمية أو سعر الوحدة.',
        'lines_total_too_large' => 'مجموع البنود يتجاوز :max، وهو أكبر مبلغ للصفقة. قسّم الصفقة أو قلّل البنود.',
    ],

    'empty' => [
        'heading' => 'لا توجد صفقات بعد',
        'description' => 'أضف أول صفقة لبدء تعبئة مسار المبيعات.',
        'line_items' => 'لا توجد بنود',
        'stage_history' => 'لا توجد تغييرات في المرحلة بعد',
        'contacts' => 'لا توجد جهات اتصال على هذه الصفقة بعد',
        'competitors' => 'لم يُسمَّ أي منافس على هذه الصفقة بعد',
    ],

];
