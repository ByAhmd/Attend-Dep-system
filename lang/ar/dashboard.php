<?php

declare(strict_types=1);

return [
    'title' => 'لوحة التحكم',
    'subheading' => 'نظرة سريعة على اليوم: :date، بتوقيت الرياض.',

    'stats' => [
        'employees_total' => 'الموظفون',
        'employees_total_hint' => 'جميع حسابات الموظفين',
        'employees_active' => 'الموظفون النشطون',
        'employees_active_hint' => 'يمكنهم تسجيل الدخول والحضور',
        'checked_in_today' => 'سجّلوا الحضور اليوم',
        'checked_in_today_hint' => 'موظفون سجّلوا جلسة اليوم',
        'checked_out_today' => 'سجّلوا الانصراف اليوم',
        'checked_out_today_hint' => 'موظفون أكملوا جلسة اليوم',
        'currently_checked_in' => 'الحاضرون الآن',
        'currently_checked_in_hint' => 'سجّلوا الحضور ولم ينصرفوا بعد',
        'pending_corrections' => 'طلبات تصحيح بانتظار قرار',
        'pending_corrections_hint' => 'طلبات لم يُبتّ فيها بعد',
        'pending_leave' => 'طلبات إجازة بانتظار قرار',
        'pending_leave_hint' => 'طلبات لم يُبتّ فيها بعد',
    ],

    /*
     | قائمة المتأخرين. وصفها يذكر الحساب صراحةً - يُرصد بعد :threshold
     | ويُقاس من :start - لأن قارئًا يقابل «حضر 09:45» بجانب «متأخر 45 د»
     | يجب أن يجد القاعدة مكتوبة لا أن يستنتجها.
     */
    'late' => [
        'heading' => 'المتأخرون اليوم',
        'description' => 'أول حضور في اليوم إذا جاء بعد :threshold. يُقاس التأخير من بداية الدوام الساعة :start.',
        'first_check_in' => 'أول حضور',
        'lateness' => 'مقدار التأخير',
        'empty_heading' => 'لا متأخرين اليوم',
        'empty_description' => 'كل من سجّل حضوره اليوم جاء في الوقت.',
    ],

    'shortcuts' => [
        'employees' => 'الموظفون',
        'attendance' => 'سجلات الحضور',
        'settings' => 'الإعدادات',
    ],
];
