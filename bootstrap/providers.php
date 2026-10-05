<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\HomePanelProvider;
use App\Providers\Filament\ReportsPanelProvider;
use App\Providers\Filament\SalesPanelProvider;
use App\Providers\Filament\TasksPanelProvider;

return [
    AppServiceProvider::class,
    HomePanelProvider::class,
    SalesPanelProvider::class,
    TasksPanelProvider::class,
    ReportsPanelProvider::class,
    AdminPanelProvider::class,
];
