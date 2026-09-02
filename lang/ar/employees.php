<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'الموظفون',
        'model' => 'موظف',
        'plural_model' => 'الموظفون',
    ],

    'sections' => [
        'details' => 'بيانات الموظف',
        'access' => 'الصلاحيات',
        'password' => 'كلمة المرور',
    ],

    'fields' => [
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'new_password' => 'كلمة المرور الجديدة',
        'role' => 'الدور',
        'status' => 'الحالة',
        'created_at' => 'تاريخ الإنشاء',
    ],

    'placeholders' => [
        'name' => 'الاسم الكامل كما سيظهر في سجلات الحضور',
        'email' => 'name@company.com',
    ],

    'helpers' => [
        'password' => '8 أحرف على الأقل. شاركها مع الموظف؛ لا يمكن إعادة تعيينها إلا بواسطة مسؤول.',
        'status' => 'الموظف غير النشط لا يستطيع تسجيل الدخول أو تسجيل الحضور. يُحتفظ بسجله.',
        'role' => 'المسؤول يدير الموظفين والإعدادات؛ الموظف يسجّل الحضور والانصراف فقط.',
        'own_access' => 'لا يمكنك تغيير دورك أو حالتك.',
    ],

    'validation' => [
        'email_unique' => 'هذا البريد الإلكتروني مستخدم بالفعل.',
    ],

    'filters' => [
        'role' => 'الدور',
        'status' => 'الحالة',
    ],

    'actions' => [
        'reset_password' => 'إعادة تعيين كلمة المرور',
        'reset_password_heading' => 'إعادة تعيين كلمة مرور :name',
        'reset_password_description' => 'ستتوقف كلمة المرور الحالية عن العمل فورًا.',
        'activate' => 'تفعيل',
        'activate_heading' => 'تفعيل :name؟',
        'deactivate' => 'إلغاء التفعيل',
        'deactivate_heading' => 'إلغاء تفعيل :name؟',
        'deactivate_description' => 'سيتم تسجيل خروجه ولن يتمكن من تسجيل الحضور حتى إعادة تفعيله. يُحتفظ بسجل حضوره.',
    ],

    'notifications' => [
        'password_reset' => 'تم تحديث كلمة المرور',
        'password_reset_body' => 'كلمة المرور الجديدة لـ :name سارية فورًا.',
        'activated' => ':name أصبح نشطًا',
        'deactivated' => ':name أصبح غير نشط',
    ],

    'empty' => [
        'heading' => 'لا يوجد موظفون بعد',
        'description' => 'أضف أول موظف ليتمكن من تسجيل الحضور.',
    ],

    'pages' => [
        'list' => [
            'subheading' => 'كل من يستطيع تسجيل الدخول، وما المسموح له به.',
        ],
    ],
];
