<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;

/**
 * The panel landing page. KPI widgets, charts and filters are added by the
 * dashboard step of the plan (docs/ARCHITECTURE_PLAN.md section 5, step 10);
 * until then it shows the account card only.
 */
final class Dashboard extends BaseDashboard
{
    public static function getNavigationLabel(): string
    {
        return __('dashboard.navigation');
    }

    public function getTitle(): string
    {
        return __('dashboard.title');
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            AccountWidget::class,
        ];
    }
}
