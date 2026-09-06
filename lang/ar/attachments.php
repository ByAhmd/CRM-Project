<?php

declare(strict_types=1);

// المرفقات (الوحدة 13، القرار D-13).
return [

    'navigation' => [
        'label' => 'المرفقات',
        'model' => 'مرفق',
        'plural_model' => 'المرفقات',
    ],

    'fields' => [
        'file' => 'الملف',
        'original_name' => 'اسم الملف',
        'mime_type' => 'النوع',
        'size' => 'الحجم',
        'description' => 'الوصف',
        'uploaded_by' => 'رفعه',
        'created_at' => 'تاريخ الرفع',
    ],

    'options' => [
        'mime' => [
            'image' => 'صورة',
            'pdf' => 'PDF',
            'document' => 'مستند',
            'spreadsheet' => 'جدول بيانات',
            'text' => 'نص',
            'other' => 'أخرى',
        ],
    ],

    'helpers' => [
        'file' => 'المسموح: :types. الحد الأقصى للحجم :size.',
        'list_separator' => '، ',
    ],

    'filters' => [
        'trashed' => 'المحذوفة',
    ],

    'actions' => [
        'upload' => 'رفع ملف',
        'upload_heading' => 'رفع ملف',
        'upload_submit' => 'رفع',
        'download' => 'تنزيل',
        'delete' => 'حذف',
        'restore' => 'استعادة',
    ],

    'notifications' => [
        'uploaded' => 'تم رفع الملف',
        'deleted' => 'تم حذف الملف',
        'restored' => 'تمت استعادة الملف',
    ],

    'validation' => [
        'mime_not_allowed' => 'الملفات من نوع ":mime" غير مسموح بها.',
        'too_large' => 'حجم الملف يتجاوز الحد المسموح :max.',
        'file_missing' => 'تعذر العثور على الملف المرفوع. يرجى رفعه مرة أخرى.',
        'storage_failed' => 'تعذر حفظ الملف. يرجى المحاولة مرة أخرى.',
    ],

    'empty' => [
        'heading' => 'لا توجد ملفات بعد',
        'description' => 'ارفع عرض سعر أو عقدًا أو أي مستند يخص هذا السجل.',
    ],

];
