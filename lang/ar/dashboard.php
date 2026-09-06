<?php

declare(strict_types=1);

return [
    'navigation' => 'لوحة المعلومات',
    'title' => 'لوحة المعلومات',

    'filters' => [
        'period' => 'الفترة',
        'from' => 'من',
        'to' => 'إلى',
        'owner' => 'المالك',
        'team' => 'الفريق',
        'pipeline' => 'خط المبيعات',
        'reset' => 'إعادة ضبط المرشحات',
    ],

    'placeholders' => [
        'all_owners' => 'كل الملّاك',
        'all_teams' => 'كل الفرق',
        'all_pipelines' => 'كل خطوط المبيعات',
    ],

    'helpers' => [
        'owner' => 'الأشخاص الذين يحق لك رؤية سجلاتهم فقط.',
        'range' => 'حتى :days يومًا.',
    ],

    'validation' => [
        'range_too_large' => 'لا يجوز أن تتجاوز الفترة :days يومًا.',
        'to_before_from' => 'يجب أن يكون تاريخ النهاية في تاريخ البداية أو بعده.',
    ],

    'kpis' => [
        'new_leads' => 'عملاء محتملون جدد',
        'new_leads_description' => ':qualified مؤهل · :converted محوّل',
        'conversion_rate' => 'معدل التحويل',
        'conversion_rate_description' => 'المحوّلون من العملاء المحتملين المُنشأين في الفترة',
        'open_deals' => 'صفقات مفتوحة',
        'open_deals_description' => ':amount',
        'weighted_pipeline' => 'خط المبيعات المرجّح',
        'weighted_pipeline_description' => 'المبلغ × الاحتمال للصفقات المفتوحة',
        'won' => 'مكسوبة',
        'won_description' => ':amount',
        'lost' => 'خاسرة',
        'lost_description' => ':amount',
        'win_rate' => 'معدل الفوز',
        'win_rate_description' => 'المكسوبة من الصفقات المغلقة في الفترة',
    ],

    'charts' => [
        'leads_by_status' => 'العملاء المحتملون المفتوحون حسب الحالة',
        'pipeline_by_stage' => 'الصفقات المفتوحة حسب المرحلة',
        'pipeline_by_stage_default' => 'الصفقات المفتوحة حسب المرحلة · :pipeline',
        'revenue_by_month' => 'الإيرادات المكسوبة حسب الشهر',
        'activity_counts' => 'الأنشطة حسب النوع',
        'dataset_amount' => 'المبلغ',
        'dataset_weighted' => 'المرجّح',
        'dataset_count' => 'العدد',
        'dataset_leads' => 'العملاء المحتملون',
        'dataset_revenue' => 'المبلغ المكسوب',
    ],

    'tables' => [
        'my_tasks_today' => 'مهامي اليوم',
        'my_tasks_today_description' => ':today مستحقة اليوم · :overdue متأخرة',
        'upcoming_follow_ups' => 'المتابعات القادمة',
        'upcoming_follow_ups_description' => 'المتابعات والمكالمات والاجتماعات خلال :days أيام القادمة',
        'stale_deals' => 'صفقات راكدة',
        'stale_deals_description' => 'صفقات مفتوحة بلا نشاط منذ :days يومًا',
        'columns' => [
            'title' => 'العنوان',
            'kind' => 'النوع',
            'priority' => 'الأولوية',
            'due_at' => 'الاستحقاق',
            'subject' => 'مرتبطة بـ',
            'assignee' => 'المكلّف',
            'account' => 'الحساب',
            'stage' => 'المرحلة',
            'amount' => 'المبلغ',
            'owner' => 'المالك',
            'last_activity_at' => 'آخر نشاط',
        ],
    ],

    'empty' => [
        'tasks' => 'لا شيء مستحق اليوم',
        'tasks_description' => 'لا توجد مهمة مفتوحة مكلّف بها مستحقة أو متأخرة.',
        'follow_ups' => 'لا متابعات قادمة',
        'follow_ups_description' => 'لا توجد متابعة أو مكالمة أو اجتماع مستحق خلال الأيام القادمة.',
        'stale_deals' => 'لا صفقات راكدة',
        'stale_deals_description' => 'كل صفقة مفتوحة في نطاقك جرى التعامل معها مؤخرًا.',
        'chart' => 'لا شيء لعرضه',
        'chart_description' => 'لا توجد سجلات تطابق المرشحات الحالية.',
    ],
];
