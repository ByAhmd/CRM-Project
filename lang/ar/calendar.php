<?php

declare(strict_types=1);

// صفحة التقويم: المهام والاجتماعات والمكالمات حسب التاريخ (القرار D-12).
return [

    'navigation' => 'التقويم',
    'title' => 'التقويم',

    'legend' => [
        'heading' => 'دليل الألوان',
        'tasks' => 'المهام حسب الأولوية',
        'meetings' => 'الاجتماعات',
        'calls' => 'المكالمات',
        'completed' => 'مكتملة',
    ],

    'views' => [
        'month' => 'شهر',
        'week' => 'أسبوع',
        'day' => 'يوم',
        'list' => 'قائمة',
    ],

    'buttons' => [
        'today' => 'اليوم',
        'prev' => 'السابق',
        'next' => 'التالي',
    ],

    'texts' => [
        'all_day' => 'طوال اليوم',
        'no_events' => 'لا يوجد شيء مجدول في هذه الفترة',
        'more' => '+:count أخرى',
        'truncated' => 'تضم هذه الفترة أكثر من :count إدخال، فتُعرض أول :count منها فقط. انتقل إلى عرض الأسبوع أو اليوم لرؤية الباقي.',
    ],

    'actions' => [
        'create_task' => 'إضافة مهمة',
        'create_task_heading' => 'إضافة مهمة',
        'create_task_submit' => 'إضافة المهمة',
        'edit_task' => 'تعديل المهمة',
        'edit_task_heading' => 'تعديل المهمة',
        'edit_task_submit' => 'حفظ التغييرات',
        'complete' => 'إكمال',
    ],

    'notifications' => [
        'moved' => 'نُقلت :task إلى :date',
        'created' => 'أُضيفت المهمة',
        'updated' => 'حُدِّثت المهمة',
        'refused' => 'تعذّر نقل المهمة',
    ],

    'validation' => [
        'range_too_large' => 'يحمّل التقويم :days يوماً كحد أقصى في المرة الواحدة.',
        'invalid_date' => 'هذا التاريخ غير صالح.',
    ],

    'empty' => [
        'description' => 'انقر على يوم لإضافة مهمة، أو سجّل اجتماعاً أو مكالمة من أحد السجلات.',
    ],

];
