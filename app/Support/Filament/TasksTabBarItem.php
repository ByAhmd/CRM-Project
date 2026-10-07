<?php

declare(strict_types=1);

namespace App\Support\Filament;

use Filament\Support\Icons\Heroicon;

/**
 * One tab of the Tasks phone bar (TasksTabBar, D-27).
 */
final readonly class TasksTabBarItem
{
    public function __construct(
        public string $key,
        public string $label,
        public Heroicon $icon,
        public string $url,
        public bool $current = false,
        public bool $navigate = true,
        public bool $primary = false,
        public ?int $badge = null,
    ) {}
}
