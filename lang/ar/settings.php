<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'إعدادات الحضور',
        'model' => 'إعدادات الحضور',
        'plural_model' => 'إعدادات الحضور',
    ],

    'sections' => [
        'location' => 'موقع الشركة',
        'radius' => 'النطاق المسموح',
        'working_hours' => 'ساعات الدوام الرسمية',
        'corrections' => 'طلبات التصحيح',
    ],

    'fields' => [
        'latitude' => 'خط العرض',
        'longitude' => 'خط الطول',
        'radius_meters' => 'النطاق المسموح (بالأمتار)',
        'radius_suffix' => 'م',
        'correction_requests_per_month' => 'عدد طلبات التصحيح شهريًا',
        'work_starts_at' => 'بداية الدوام',
        'work_ends_at' => 'نهاية الدوام',
        'late_grace_minutes' => 'مهلة التأخير',
        'late_grace_suffix' => 'دقيقة',
    ],

    /*
     | إحداثيات نموذجية تُظهر الشكل المتوقع للقيمة: خط عرض قريب من 24 وخط
     | طول قريب من 46 في الرياض، حتى يبدو الزوج المعكوس خاطئًا قبل حفظه.
     */
    'placeholders' => [
        'latitude' => '24.7136000',
        'longitude' => '46.6753000',
    ],

    'helpers' => [
        /*
         | The worked pair is wrapped in a Unicode left-to-right isolate
         | (U+2066 opening it, U+2069 closing it). Without one, the Arabic
         | paragraph direction reorders the neutral characters around it and
         | the browser prints the longitude first - on the one screen whose
         | entire failure mode is entering the two numbers the wrong way
         | round.
         */
        'coordinates' => "افتح خرائط Google، اضغط مطوّلًا على مدخل الشركة، ثم انسخ الرقمين الظاهرين (مثال: \u{2066}24.7136, 46.6753\u{2069}).",
        'radius' => 'الافتراضي 150 مترًا. يجب أن يكون الموظف ضمن هذه المسافة من الإحداثيات أعلاه لتسجيل الحضور أو الانصراف.',
        'not_configured' => 'لا يمكن تسجيل الحضور حتى يتم ضبط موقع الشركة.',
        'preview' => 'فتح الموقع المضبوط في الخريطة',
        'correction_requests_per_month' => 'أقصى عدد طلبات يرسلها الموظف الواحد في الشهر الميلادي. يُحتسب الطلب سواء قُبل أو رُفض. الصفر يوقف طلبات التصحيح تمامًا.',
        'working_hours' => 'الحضور بعد بداية الدوام بأكثر من المهلة يُرصد متأخرًا، والانصراف قبل نهاية الدوام يتطلب من الموظف اختيار سبب.',
        'late_grace_minutes' => 'عدد الدقائق بعد بداية الدوام التي لا يُرصد الحضور خلالها متأخرًا. الصفر يعني أن أي حضور بعد البداية متأخر.',
    ],

    'validation' => [
        'latitude' => 'يجب أن يكون خط العرض رقمًا بين -90 و90.',
        'longitude' => 'يجب أن يكون خط الطول رقمًا بين -180 و180.',
        'radius' => 'يجب أن يكون النطاق رقمًا صحيحًا بين :min و:max مترًا.',
        'correction_quota_required' => 'اذكر عددًا، ولو صفرًا.',
        'correction_quota_min' => 'أقل قيمة هي صفر، وتعني إيقاف طلبات التصحيح.',
        'correction_quota_max' => 'أعلى قيمة هي :max، أي طلب لكل يوم من الشهر.',
        'correction_quota_integer' => 'اذكر عددًا صحيحًا.',
        'work_time' => 'اذكر وقتًا صالحًا.',
        'working_day_ordered' => 'يجب أن تكون نهاية الدوام بعد بدايته.',
        'late_grace' => 'يجب أن تكون المهلة عددًا صحيحًا بين :min و:max دقيقة.',
    ],

    'notifications' => [
        'saved' => 'تم حفظ إعدادات الحضور',
    ],

    'pages' => [
        'edit' => [
            'title' => 'إعدادات الحضور',
            'subheading' => 'أين تقع الشركة، وما المسافة التي يجب أن يكون الموظف ضمنها لتسجيل الحضور أو الانصراف.',
        ],
    ],
];
