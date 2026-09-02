<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\EmployeePanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    EmployeePanelProvider::class,
];
