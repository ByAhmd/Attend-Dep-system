<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'المحاولات المرفوضة',
        'model' => 'محاولة مرفوضة',
        'plural_model' => 'المحاولات المرفوضة',
    ],

    'fields' => [
        'employee' => 'الموظف',
        'action' => 'العملية',
        'reason' => 'السبب',
        'distance' => 'المسافة',
        'accuracy' => 'الدقة',
        'recorded_at' => 'وقت التسجيل',
    ],

    'filters' => [
        'employee' => 'الموظف',
        'reason' => 'السبب',
        'action' => 'العملية',
        'date_range' => 'الفترة',
        'from' => 'من',
        'until' => 'إلى',
    ],

    'actions' => [
        'open_map' => 'فتح في الخريطة',
    ],

    'empty' => [
        'heading' => 'لا توجد محاولات مرفوضة',
        'description' => 'ستُدرج هنا عمليات الحضور والانصراف التي رُفضت بسبب الموقع.',
    ],

    'pages' => [
        'list' => [
            'subheading' => 'عمليات الحضور والانصراف التي رُفضت بسبب موقع الجهاز أو دقته.',
        ],
    ],
];
