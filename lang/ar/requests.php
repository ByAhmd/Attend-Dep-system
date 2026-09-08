<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'طلباتي',
        'subheading' => 'ما طلبته، وما صار إليه.',
    ],

    'tiles' => [
        'heading' => 'عمليات سريعة',
        'correction' => 'تصحيح تسجيل',
        'leave' => 'طلب إجازة',
        'my_requests' => 'طلباتي',
        'attendance' => 'الحضور',
        'badge_pending' => ':count بانتظار القرار',
        'badge_quota' => ':count متبقية',
        'badge_no_quota' => 'لا يوجد رصيد هذا الشهر',
        // البطاقة تحمل سطرها الثاني دائمًا، حتى حين لا يوجد ما ينتظر قرارًا:
        // رقم لا يظهر إلا حين يسوء يعلّم القارئ ألّا يثق بغيابه.
        'badge_no_pending' => 'لا شيء بانتظار القرار',
        'badge_corrections_off' => 'طلبات التصحيح موقوفة',
        'caption_correction' => 'وقت حضور أو انصراف تريد تصحيحه',
        'caption_leave' => 'يوم كامل، أو خروج وعودة',
        'caption_attendance' => 'العودة إلى شاشة الحضور',
    ],

    'fields' => [
        'status' => 'الحالة',
        'decision_note' => 'ملاحظة الإدارة',
    ],

    'placeholders' => [
        'no_note' => 'لا توجد ملاحظة',
    ],

    'feedback' => [
        'too_many_attempts' => 'محاولات كثيرة. انتظر قليلًا ثم أعد المحاولة.',
    ],
];
