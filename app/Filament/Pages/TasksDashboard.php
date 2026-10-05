<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\BuildsPageUrlsInOwningPanel;
use App\Filament\Widgets\MyTasksTodayWidget;
use App\Filament\Widgets\UpcomingFollowUpsWidget;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The home page of the Tasks workspace (decision D-23): the viewer's own
 * attention list — tasks overdue or due today — beside the follow-ups,
 * calls and meetings coming up, the two task lists that used to sit on the
 * single panel's dashboard. Both run on fixed windows inside the viewer's
 * scope (D-4), so the page has no filters; each list is gated by its own
 * canView().
 */
final class TasksDashboard extends BaseDashboard
{
    use BuildsPageUrlsInOwningPanel;

    public static function getNavigationLabel(): string
    {
        return __('workspaces.navigation.tasks_home');
    }

    public function getTitle(): string
    {
        return __('workspaces.pages.tasks_home.title');
    }

    public function getSubheading(): string
    {
        return __('workspaces.pages.tasks_home.subheading');
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            MyTasksTodayWidget::class,
            UpcomingFollowUpsWidget::class,
        ];
    }

    /**
     * One column on phones, two from md up: the two lists pair on desktop
     * (each spans ['default' => 'full', 'lg' => 1]). Every breakpoint is
     * declared so no width relies on the grid's implicit fallback.
     *
     * @return array<string, int>
     */
    public function getColumns(): array
    {
        return [
            'default' => 1,
            'md' => 2,
        ];
    }
}
