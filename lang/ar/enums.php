<?php

declare(strict_types=1);

// Every enum label lives here and nowhere else.
return [

    'user_status' => [
        'active' => 'نشط',
        'pending' => 'بانتظار قبول الدعوة',
        'disabled' => 'موقوف',
    ],

    'roles' => [
        'super_admin' => 'مدير النظام الأعلى',
        'admin' => 'مدير النظام',
        'sales_manager' => 'مدير المبيعات',
        'sales_rep' => 'مندوب مبيعات',
        'support' => 'دعم',
        'read_only' => 'قراءة فقط',
    ],

    'visibility_level' => [
        'none' => 'لا شيء',
        'own' => 'سجلاته فقط',
        'team' => 'سجلات فريقه',
        'all' => 'كل السجلات',
    ],

    'lead_status_kind' => [
        'new' => 'جديد',
        'working' => 'قيد المتابعة',
        'qualified' => 'مؤهل',
        'unqualified' => 'غير مؤهل',
        'converted' => 'محوّل',
    ],

    'stage_kind' => [
        'open' => 'مفتوحة',
        'won' => 'مكسوبة',
        'lost' => 'مفقودة',
    ],

    'activity_kind' => [
        'call' => 'مكالمة',
        'meeting' => 'اجتماع',
        'email' => 'بريد إلكتروني',
        'note' => 'ملاحظة',
        'task' => 'مهمة',
        'system' => 'نظامي',
        'other' => 'أخرى',
    ],

    'close_reason_kind' => [
        'won' => 'سبب الفوز',
        'lost' => 'سبب الخسارة',
    ],

    'badge_color' => [
        'primary' => 'أزرق',
        'gray' => 'رمادي',
        'success' => 'أخضر',
        'warning' => 'برتقالي',
        'danger' => 'أحمر',
        'info' => 'سماوي',
    ],

    'account_type' => [
        'prospect' => 'عميل مرتقب',
        'customer' => 'عميل',
        'partner' => 'شريك',
        'other' => 'أخرى',
    ],

    'company_size' => [
        '1_10' => '1 – 10 موظفين',
        '11_50' => '11 – 50 موظفاً',
        '51_200' => '51 – 200 موظف',
        '201_500' => '201 – 500 موظف',
        '501_1000' => '501 – 1000 موظف',
        '1000_plus' => 'أكثر من 1000 موظف',
    ],

    'lead_priority' => [
        'low' => 'منخفضة',
        'medium' => 'متوسطة',
        'high' => 'عالية',
    ],

    'lead_scoring_rule_kind' => [
        'source' => 'مصدر العميل المحتمل',
        'status' => 'حالة العميل المحتمل',
        'field_filled' => 'حقل معبّأ',
        'activity_recency' => 'نشاط حديث',
    ],

    'deal_status' => [
        'open' => 'مفتوحة',
        'won' => 'مكسوبة',
        'lost' => 'مفقودة',
    ],

    'forecast_category' => [
        'pipeline' => 'قيد المتابعة',
        'best_case' => 'أفضل حالة',
        'commit' => 'مؤكدة',
        'omitted' => 'مستبعدة',
    ],

    'task_status' => [
        'pending' => 'قيد الانتظار',
        'in_progress' => 'قيد التنفيذ',
        'completed' => 'مكتملة',
        'cancelled' => 'ملغاة',
    ],

    'task_priority' => [
        'low' => 'منخفضة',
        'medium' => 'متوسطة',
        'high' => 'عالية',
        'urgent' => 'عاجلة',
    ],

    'task_kind' => [
        'task' => 'مهمة',
        'follow_up' => 'متابعة',
        'call' => 'مكالمة',
        'meeting' => 'اجتماع',
    ],

    'recurrence_frequency' => [
        'none' => 'لا تتكرر',
        'daily' => 'يومياً',
        'weekly' => 'أسبوعياً',
        'monthly' => 'شهرياً',
    ],

    'activity_direction' => [
        'inbound' => 'وارد',
        'outbound' => 'صادر',
    ],

    'deal_contact_role' => [
        'decision_maker' => 'صاحب القرار',
        'influencer' => 'مؤثر',
        'champion' => 'داعم',
        'user' => 'مستخدم',
        'other' => 'أخرى',
    ],

    'custom_field_type' => [
        'text' => 'نص قصير',
        'textarea' => 'نص طويل',
        'number' => 'رقم صحيح',
        'decimal' => 'رقم عشري',
        'date' => 'تاريخ',
        'datetime' => 'تاريخ ووقت',
        'boolean' => 'نعم / لا',
        'select' => 'اختيار واحد',
        'multiselect' => 'اختيار متعدد',
        'url' => 'رابط',
        'email' => 'بريد إلكتروني',
    ],

    'notification_event' => [
        'record_assigned' => 'إسناد سجل إليّ',
        'task_reminder' => 'تذكير بمهمة',
        'task_overdue' => 'تأخر مهمة',
        'deal_stage_changed' => 'تغيير مرحلة صفقة أنا مسؤول عنها',
        'deal_closed' => 'الفوز بصفقة أنا مسؤول عنها أو خسارتها',
        'lead_converted' => 'تحويل عميل محتمل أنا مسؤول عنه',
        'lead_stale' => 'ركود عميل محتمل أنا مسؤول عنه',
        'note_mention' => 'الإشارة إليّ في ملاحظة',
    ],

    'custom_field_entity' => [
        'lead' => 'العملاء المحتملون',
        'contact' => 'جهات الاتصال',
        'account' => 'الحسابات',
        'deal' => 'الصفقات',
    ],

];
