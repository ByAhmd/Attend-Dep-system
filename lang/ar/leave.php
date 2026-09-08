<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'طلبات الإجازة',
        'model' => 'طلب إجازة',
        'plural_model' => 'طلبات الإجازة',
    ],

    'sections' => [
        'request' => 'الطلب',
        'conflicts' => 'ما يتعارض معها',
        'decision' => 'القرار',
    ],

    'fields' => [
        'employee' => 'الموظف',
        'type' => 'نوع الإجازة',
        'period' => 'المدة',
        'starts_on' => 'البداية',
        'ends_on' => 'النهاية',
        'days' => 'عدد الأيام',
        'is_exit_and_return' => 'خروج وعودة',
        'reason' => 'السبب',
        'status' => 'الحالة',
        'submitted_at' => 'تاريخ الإرسال',
        'decided_by' => 'القرار من',
        'decided_at' => 'تاريخ القرار',
        'decision_note' => 'ملاحظة القرار',
        'attended_days' => 'أيام سُجِّل فيها حضور',
        'overlapping_leave' => 'إجازة أخرى تغطي هذه الأيام',
        'attachment' => 'مستند مساند',
    ],

    'placeholders' => [
        'reason' => 'اكتب سبب الإجازة',
        'no_overlap' => 'لا يوجد تعارض',
        'no_note' => 'لا توجد ملاحظة',
        'no_attachment' => 'لا يوجد مستند',
        'days' => 'اختر التاريخين',
    ],

    'helpers' => [
        'range' => 'يمكن طلب إجازة بدأت خلال الثلاثين يومًا الماضية، أو تبدأ خلال سنة من اليوم.',
        'is_exit_and_return' => 'ساعات خارج الدوام في اليوم نفسه، لا يوم كامل.',
        'reason' => 'يقرأ المسؤول هذا النص قبل اتخاذ القرار.',
        'attachment' => 'اختياري: ملف واحد يدعم طلبك — تقرير طبي أو جدول اختبارات مثلًا. PDF أو JPEG أو PNG، وبحد أقصى :size ميغابايت. لا يراه إلا الإدارة.',
        'approve_consequence' => 'تُسجَّل الموافقة على هذه الأيام. لا يمنع ذلك الموظف من تسجيل الحضور إن حضر.',
        'reject_consequence' => 'سيرى الموظف ملاحظتك مكان الموافقة التي طلبها.',
        'decision_note_optional' => 'اختيارية. يراها الموظف بجانب القرار.',
        'decision_note_required' => 'يراها الموظف مكان الموافقة التي طلبها، فاذكر السبب.',
    ],

    // A choice string, read with trans_choice(): Arabic counts in four
    // bands, and ":count يوم" would print «٥ يوم» on every request longer
    // than two days.
    'units' => [
        'days' => '{1} يوم واحد|{2} يومان|[3,10] :count أيام|[11,*] :count يومًا',
    ],

    'summaries' => [
        'total_days' => 'مجموع الأيام',
    ],

    'validation' => [
        'type_required' => 'اختر نوع الإجازة.',
        'starts_required' => 'اختر تاريخ البداية.',
        'ends_required' => 'اختر تاريخ النهاية.',
        'ends_before_start' => 'تاريخ النهاية قبل تاريخ البداية.',
        'starts_too_early' => 'لا يمكن طلب إجازة بدأت قبل أكثر من ثلاثين يومًا.',
        'starts_too_late' => 'لا يمكن طلب إجازة تبدأ بعد أكثر من سنة.',
        'too_long' => 'أقصى مدة للطلب الواحد :days يومًا.',
        'reason_required' => 'اكتب سبب الإجازة.',
        'reason_min' => 'اكتب جملة على الأقل (:min أحرف).',
        'reason_max' => 'السبب أطول من المسموح (:max حرفًا).',
        'decision_note_required' => 'اكتب سبب الرفض؛ هو كل ما سيصل الموظف.',
        'attachment_type' => 'الملفات المقبولة: PDF أو JPEG أو PNG.',
        'attachment_size' => 'أقصى حجم للملف :size ميغابايت.',
    ],

    'refusals' => [
        'account_not_active' => 'حسابك غير نشط، فلا يمكن إرسال طلب.',
        'end_before_start' => 'تاريخ النهاية قبل تاريخ البداية.',
        'date_out_of_range' => 'تاريخ البداية خارج المدى المسموح.',
        'too_long' => 'الطلب الواحد لا يتجاوز :days يومًا.',
        'overlapping' => 'لديك طلب إجازة يغطي هذه الأيام بالفعل.',
        'already_decided' => 'قُرِّر هذا الطلب بالفعل. حدِّث الصفحة لترى القرار ومن اتخذه.',
        'requester_account_deleted' => 'حساب صاحب الطلب محذوف. استعِد الحساب أولًا إن كان الحذف خطأ.',
        'could_not_be_applied' => 'تعذّر حفظ القرار، ولم يتغيّر شيء. أعد المحاولة، وإن تكرر فأبلغ من يدير الخادم.',
    ],

    'filters' => [
        'status' => 'الحالة',
        'employee' => 'الموظف',
        'type' => 'نوع الإجازة',
        'period' => 'المدة',
        'from' => 'من',
        'until' => 'إلى',
        'on_leave_today' => 'في إجازة اليوم',
        'deleted_employee' => 'طلبات الحسابات المحذوفة',
        'deleted_employee_with' => 'مع الحسابات المحذوفة',
        'deleted_employee_without' => 'بدون الحسابات المحذوفة',
        'deleted_employee_only' => 'الحسابات المحذوفة فقط',
    ],

    'actions' => [
        'view' => 'فتح الطلب',
        'approve' => 'اعتماد',
        'approve_heading' => 'اعتماد إجازة :name؟',
        'approve_confirm' => 'اعتماد الإجازة',
        'reject' => 'رفض',
        'reject_heading' => 'رفض طلب :name؟',
        'reject_confirm' => 'رفض الطلب',
    ],

    'notifications' => [
        'approved' => 'تم اعتماد إجازة :name',
        'approved_body' => 'من :from إلى :until.',
        'rejected' => 'تم رفض طلب :name',
        'rejected_body' => 'لن تُسجَّل هذه الأيام كإجازة.',
        'not_applied' => 'لم يُحفظ القرار',
    ],

    'employee' => [
        'heading_table' => 'طلبات الإجازة',
        'submitted' => 'أُرسل طلب الإجازة',
        'submitted_body' => 'من :from إلى :until — بانتظار قرار الإدارة.',
        'modal_heading' => 'طلب إجازة',
        'modal_description' => 'تُسجَّل الموافقة على هذه الأيام. الإجازة لا تسجّل حضورًا ولا تمنعه إن حضرت.',
        'submit' => 'إرسال الطلب',
        'attachment_open' => 'فتح المستند',
        'empty_heading' => 'لا توجد طلبات إجازة',
        'empty_description' => 'اطلب إجازة من الشاشة الرئيسية، وستظهر هنا بحالتها.',
    ],

    'empty' => [
        'heading' => 'لا شيء بانتظار قرار',
        'description' => 'تظهر طلبات الإجازة هنا فور إرسالها. أزل عامل التصفية «الحالة» لقراءة الطلبات التي بُتّ فيها.',
    ],

    'pages' => [
        'list' => [
            'subheading' => 'طلبات الإجازة، الأقدم انتظارًا أولًا.',
        ],
    ],
];
