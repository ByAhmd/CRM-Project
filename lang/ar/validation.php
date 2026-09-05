<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| رسائل التحقق من المدخلات
|--------------------------------------------------------------------------
|
| قاعدة الواجهة ثنائية اللغة تمنع ظهور أي نص للمستخدم بلغة مكتوبة داخل الشيفرة. هذه الرسائل تأتي من
| Laravel نفسه، فبدون هذا الملف كانت كل رسالة تحقق تظهر بالإنجليزية داخل واجهة
| عربية — بما فيها أبسط حالة: ترك حقل مطلوب فارغاً.
|
| الرسائل الخاصة بحقل بعينه تبقى في ملفات الوحدات (inventory، products، …)
| عبر validationMessages()، وهذا الملف يغطي القواعد العامة فقط.
|
*/

return [

    'accepted' => 'يجب قبول :attribute.',
    'accepted_if' => 'يجب قبول :attribute عندما تكون قيمة :other هي :value.',
    'active_url' => 'يجب أن يكون :attribute رابطاً صحيحاً.',
    'after' => 'يجب أن يكون :attribute تاريخاً بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute تاريخاً بعد أو يساوي :date.',
    'alpha' => 'يجب أن يحتوي :attribute على حروف فقط.',
    'alpha_dash' => 'يجب أن يحتوي :attribute على حروف وأرقام وشرطات وشرطات سفلية فقط.',
    'alpha_num' => 'يجب أن يحتوي :attribute على حروف وأرقام فقط.',
    'any_of' => 'قيمة :attribute غير صحيحة.',
    'array' => 'يجب أن يكون :attribute مصفوفة.',
    'ascii' => 'يجب أن يحتوي :attribute على حروف وأرقام ورموز أحادية البايت فقط.',
    'base64' => 'يجب أن يكون :attribute نصاً بترميز Base64 صحيح.',
    'before' => 'يجب أن يكون :attribute تاريخاً قبل :date.',
    'before_or_equal' => 'يجب أن يكون :attribute تاريخاً قبل أو يساوي :date.',

    'between' => [
        'array' => 'يجب أن يحتوي :attribute على عدد عناصر بين :min و :max.',
        'file' => 'يجب أن يكون حجم :attribute بين :min و :max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و :max.',
        'string' => 'يجب أن يكون طول :attribute بين :min و :max حرفاً.',
    ],

    'boolean' => 'يجب أن تكون قيمة :attribute صحيحة أو خاطئة.',
    'can' => 'يحتوي :attribute على قيمة غير مصرَّح بها.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'contains' => 'ينقص :attribute قيمة مطلوبة.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => 'يجب أن يكون :attribute تاريخاً صحيحاً.',
    'date_equals' => 'يجب أن يكون :attribute تاريخاً مساوياً لـ :date.',
    'date_format' => 'يجب أن يطابق :attribute الصيغة :format.',
    'decimal' => 'يجب أن يحتوي :attribute على :decimal منزلة عشرية.',
    'declined' => 'يجب رفض :attribute.',
    'declined_if' => 'يجب رفض :attribute عندما تكون قيمة :other هي :value.',
    'different' => 'يجب أن يختلف :attribute عن :other.',
    'digits' => 'يجب أن يتكون :attribute من :digits رقماً.',
    'digits_between' => 'يجب أن يتكون :attribute من عدد أرقام بين :min و :max.',
    'dimensions' => 'أبعاد صورة :attribute غير صحيحة.',
    'distinct' => 'قيمة :attribute مكررة.',
    'doesnt_contain' => 'يجب ألا يحتوي :attribute على أي مما يلي: :values.',
    'doesnt_end_with' => 'يجب ألا ينتهي :attribute بأي مما يلي: :values.',
    'doesnt_start_with' => 'يجب ألا يبدأ :attribute بأي مما يلي: :values.',
    'email' => 'يجب أن يكون :attribute بريداً إلكترونياً صحيحاً.',
    'encoding' => 'يجب أن يكون ترميز :attribute هو :encoding.',
    'ends_with' => 'يجب أن ينتهي :attribute بأحد ما يلي: :values.',
    'enum' => 'القيمة المختارة في :attribute غير صحيحة.',
    'exists' => 'القيمة المختارة في :attribute غير صحيحة.',
    'extensions' => 'يجب أن يكون امتداد :attribute أحد ما يلي: :values.',
    'file' => 'يجب أن يكون :attribute ملفاً.',
    'filled' => 'يجب ألا يكون :attribute فارغاً.',

    'gt' => [
        'array' => 'يجب أن يحتوي :attribute على أكثر من :value عنصراً.',
        'file' => 'يجب أن يكون حجم :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من :value.',
        'string' => 'يجب أن يكون طول :attribute أكبر من :value حرفاً.',
    ],

    'gte' => [
        'array' => 'يجب أن يحتوي :attribute على :value عنصراً أو أكثر.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أكثر.',
        'numeric' => 'يجب أن تكون قيمة :attribute :value أو أكثر.',
        'string' => 'يجب أن يكون طول :attribute :value حرفاً أو أكثر.',
    ],

    'hex_color' => 'يجب أن يكون :attribute لوناً بصيغة ست عشرية صحيحة.',
    'image' => 'يجب أن يكون :attribute صورة.',
    'in' => 'القيمة المختارة في :attribute غير صحيحة.',
    'in_array' => 'يجب أن تكون قيمة :attribute موجودة ضمن :other.',
    'in_array_keys' => 'يجب أن يحتوي :attribute على واحد على الأقل من المفاتيح التالية: :values.',
    'integer' => 'يجب أن يكون :attribute عدداً صحيحاً.',
    'ip' => 'يجب أن يكون :attribute عنوان IP صحيحاً.',
    'ipv4' => 'يجب أن يكون :attribute عنوان IPv4 صحيحاً.',
    'ipv6' => 'يجب أن يكون :attribute عنوان IPv6 صحيحاً.',
    'json' => 'يجب أن يكون :attribute نص JSON صحيحاً.',
    'list' => 'يجب أن يكون :attribute قائمة.',
    'lowercase' => 'يجب أن يكون :attribute بحروف صغيرة.',

    'lt' => [
        'array' => 'يجب أن يحتوي :attribute على أقل من :value عنصراً.',
        'file' => 'يجب أن يكون حجم :attribute أصغر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أصغر من :value.',
        'string' => 'يجب أن يكون طول :attribute أصغر من :value حرفاً.',
    ],

    'lte' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :value عنصراً.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أقل.',
        'numeric' => 'يجب أن تكون قيمة :attribute :value أو أقل.',
        'string' => 'يجب أن يكون طول :attribute :value حرفاً أو أقل.',
    ],

    'mac_address' => 'يجب أن يكون :attribute عنوان MAC صحيحاً.',

    'max' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :max عنصراً.',
        'file' => 'يجب ألا يزيد حجم :attribute عن :max كيلوبايت.',
        'numeric' => 'يجب ألا تزيد قيمة :attribute عن :max.',
        'string' => 'يجب ألا يزيد طول :attribute عن :max حرفاً.',
    ],

    'max_digits' => 'يجب ألا يزيد عدد أرقام :attribute عن :max رقماً.',
    'mimes' => 'يجب أن يكون :attribute ملفاً من نوع: :values.',
    'mimetypes' => 'يجب أن يكون :attribute ملفاً من نوع: :values.',

    'min' => [
        'array' => 'يجب أن يحتوي :attribute على :min عنصراً على الأقل.',
        'file' => 'يجب ألا يقل حجم :attribute عن :min كيلوبايت.',
        'numeric' => 'يجب ألا تقل قيمة :attribute عن :min.',
        'string' => 'يجب ألا يقل طول :attribute عن :min حرفاً.',
    ],

    'min_digits' => 'يجب ألا يقل عدد أرقام :attribute عن :min رقماً.',
    'missing' => 'يجب ألا يكون :attribute موجوداً.',
    'missing_if' => 'يجب ألا يكون :attribute موجوداً عندما تكون قيمة :other هي :value.',
    'missing_unless' => 'يجب ألا يكون :attribute موجوداً إلا إذا كانت قيمة :other هي :value.',
    'missing_with' => 'يجب ألا يكون :attribute موجوداً عند وجود :values.',
    'missing_with_all' => 'يجب ألا يكون :attribute موجوداً عند وجود :values جميعها.',
    'multiple_of' => 'يجب أن تكون قيمة :attribute من مضاعفات :value.',
    'not_in' => 'القيمة المختارة في :attribute غير صحيحة.',
    'not_regex' => 'صيغة :attribute غير صحيحة.',
    'numeric' => 'يجب أن يكون :attribute رقماً.',

    'password' => [
        'letters' => 'يجب أن تحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن تحتوي :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن تحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن تحتوي :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت :attribute في تسريب بيانات سابق. الرجاء اختيار قيمة أخرى.',
    ],

    'present' => 'يجب أن يكون :attribute موجوداً.',
    'present_if' => 'يجب أن يكون :attribute موجوداً عندما تكون قيمة :other هي :value.',
    'present_unless' => 'يجب أن يكون :attribute موجوداً إلا إذا كانت قيمة :other هي :value.',
    'present_with' => 'يجب أن يكون :attribute موجوداً عند وجود :values.',
    'present_with_all' => 'يجب أن يكون :attribute موجوداً عند وجود :values جميعها.',
    'prohibited' => 'هذا الحقل :attribute غير مسموح به.',
    'prohibited_if' => 'هذا الحقل :attribute غير مسموح به عندما تكون قيمة :other هي :value.',
    'prohibited_if_accepted' => 'هذا الحقل :attribute غير مسموح به عند قبول :other.',
    'prohibited_if_declined' => 'هذا الحقل :attribute غير مسموح به عند رفض :other.',
    'prohibited_unless' => 'هذا الحقل :attribute غير مسموح به إلا إذا كانت قيمة :other ضمن :values.',
    'prohibits' => 'وجود :attribute يمنع وجود :other.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'required' => 'هذا الحقل :attribute مطلوب.',
    'required_array_keys' => 'يجب أن يحتوي :attribute على المفاتيح التالية: :values.',
    'required_if' => 'هذا الحقل :attribute مطلوب عندما تكون قيمة :other هي :value.',
    'required_if_accepted' => 'هذا الحقل :attribute مطلوب عند قبول :other.',
    'required_if_declined' => 'هذا الحقل :attribute مطلوب عند رفض :other.',
    'required_unless' => 'هذا الحقل :attribute مطلوب إلا إذا كانت قيمة :other ضمن :values.',
    'required_with' => 'هذا الحقل :attribute مطلوب عند وجود :values.',
    'required_with_all' => 'هذا الحقل :attribute مطلوب عند وجود :values جميعها.',
    'required_without' => 'هذا الحقل :attribute مطلوب عند غياب :values.',
    'required_without_all' => 'هذا الحقل :attribute مطلوب عند غياب :values جميعها.',
    'same' => 'يجب أن يتطابق :attribute مع :other.',

    'size' => [
        'array' => 'يجب أن يحتوي :attribute على :size عنصراً.',
        'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute :size.',
        'string' => 'يجب أن يكون طول :attribute :size حرفاً.',
    ],

    'starts_with' => 'يجب أن يبدأ :attribute بأحد ما يلي: :values.',
    'string' => 'يجب أن يكون :attribute نصاً.',
    'timezone' => 'يجب أن يكون :attribute منطقة زمنية صحيحة.',
    'unique' => 'قيمة :attribute مستخدمة من قبل.',
    'uploaded' => 'تعذّر رفع :attribute.',
    'uppercase' => 'يجب أن يكون :attribute بحروف كبيرة.',
    'url' => 'يجب أن يكون :attribute رابطاً صحيحاً.',
    'ulid' => 'يجب أن يكون :attribute معرّف ULID صحيحاً.',
    'uuid' => 'يجب أن يكون :attribute معرّف UUID صحيحاً.',

    /*
    |--------------------------------------------------------------------------
    | رسائل مخصصة لحقول بعينها
    |--------------------------------------------------------------------------
    |
    | الرسائل الخاصة بحقل واحد تُكتب في ملف وحدته عبر validationMessages()
    | داخل نموذج Filament، حيث تبقى بجوار القاعدة التي تنتجها. هذا المفتاح
    | مُبقى لأن Laravel يبحث فيه قبل الرسائل العامة أعلاه.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'رسالة مخصصة',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | أسماء الحقول
    |--------------------------------------------------------------------------
    |
    | نماذج Filament تمرر label() صريحاً من ملف وحدتها، فلا تُكرَّر أسماؤها هنا
    | حتى لا يوجد مصدران للاسم الواحد قد يتعارضان. الاستثناء الوحيد هو نموذج
    |
    */

    'attributes' => [
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'phone' => 'رقم الجوال',
        'current_password' => 'كلمة المرور الحالية',
    ],

];
