<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'المسميات الوظيفية',
        'model' => 'مسمى وظيفي',
        'plural_model' => 'المسميات الوظيفية',
    ],

    'sections' => [
        'details' => 'بيانات المسمى',
    ],

    'fields' => [
        'name_ar' => 'الاسم بالعربية',
        'name_en' => 'الاسم بالإنجليزية',
        'holders' => 'من يحملونه',
        'status' => 'الحالة',
        'created_at' => 'تاريخ الإضافة',
    ],

    'placeholders' => [
        'name_ar' => 'التسويق',
        'name_en' => 'Marketing',
    ],

    'helpers' => [
        'name' => 'يظهر هذا المسمى تحت اسم الموظف، ويُقرأ كلٌّ منهما بلغة القارئ، لذلك الاسمان مطلوبان.',
        'is_active' => 'المسمى المتقاعد يبقى على من يحملونه ولا يُعرض عند إضافة موظف جديد.',
    ],

    'badges' => [
        'active' => 'مفعَّل',
        'retired' => 'متقاعد',
    ],

    'validation' => [
        'name_ar_unique' => 'يوجد مسمى بهذا الاسم العربي بالفعل.',
        'name_en_unique' => 'يوجد مسمى بهذا الاسم الإنجليزي بالفعل.',
    ],

    'filters' => [
        'is_active' => 'الحالة',
        'any' => 'الكل',
        'active_only' => 'المفعَّلة فقط',
        'retired_only' => 'المتقاعدة فقط',
    ],

    'actions' => [
        'toggle_retire' => 'تقاعد المسمى',
        'toggle_retire_heading' => 'تقاعد «:name»؟',
        'toggle_retire_description' => 'يبقى على من يحملونه الآن، ويختفي من قائمة المسميات عند إضافة موظف جديد.',
        'toggle_activate' => 'إعادة التفعيل',
        'toggle_activate_heading' => 'إعادة تفعيل «:name»؟',
        'toggle_activate_description' => 'سيعود المسمى إلى قائمة المسميات المتاحة.',
        'delete' => 'حذف',
        'delete_heading' => 'حذف «:name»؟',
        'delete_description' => 'لا يحمل هذا المسمى أي حساب، فحذفه لا يؤثر على أي سجل.',
        // Read with trans_choice(): the holder count decides the noun, and
        // «٣ حساب» is not a sentence anybody writes.
        'delete_blocked_description' => '{1} يحمل هذا المسمى حساب واحد، ولا يمكن حذفه ما دام أحد يحمله. لتوقف استخدامه استخدم «تقاعد المسمى».|{2} يحمل هذا المسمى حسابان، ولا يمكن حذفه ما دام أحد يحمله. لتوقف استخدامه استخدم «تقاعد المسمى».|[3,10] يحمل هذا المسمى :count حسابات، ولا يمكن حذفه ما دام أحد يحمله. لتوقف استخدامه استخدم «تقاعد المسمى».|[11,*] يحمل هذا المسمى :count حسابًا، ولا يمكن حذفه ما دام أحد يحمله. لتوقف استخدامه استخدم «تقاعد المسمى».',
        'delete_confirm' => 'حذف المسمى',
        'show_holders' => 'عرض من يحملونه',
    ],

    'notifications' => [
        'deleted' => 'تم حذف «:name»',
        'retired' => 'تقاعد «:name»',
        'activated' => 'عاد «:name» إلى الخدمة',
        'delete_blocked' => 'تعذّر حذف «:name»',
        'delete_blocked_body' => 'أُسند هذا المسمى إلى حساب قبل قليل. حدِّث الصفحة لترى من يحمله.',
    ],

    'empty' => [
        'heading' => 'لا توجد مسميات وظيفية',
        'description' => 'أضف المسميات التي تستخدمها الشركة لتظهر في نموذج الموظف.',
    ],

    'pages' => [
        'list' => [
            'subheading' => 'المسميات التي يمكن إسنادها إلى الحسابات. المسمى وصف للشخص لا صلاحية له.',
        ],
    ],
];
