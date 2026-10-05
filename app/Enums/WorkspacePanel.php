<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * The four workspaces the CRM is split into (decision D-23): one Filament
 * panel each, entered by its own permission, chosen from the workspace
 * screen after sign-in. Case order IS the order of the workspace screen and
 * of the top-bar switcher — do not sort.
 *
 * The fifth panel, the home panel (HOME, at /app), is deliberately not a
 * case: it is not a workspace and needs no permission — it hosts sign-in,
 * password reset, profile and two-step verification, notification
 * preferences and the workspace screen itself, open to every user who may
 * sign in (D-11).
 */
enum WorkspacePanel: string implements HasDescription, HasIcon, HasLabel
{
    case Sales = 'sales';
    case Tasks = 'tasks';
    case Reports = 'reports';
    case Admin = 'admin';

    /** The id (and the path) of the home panel: the login host and the workspace screen. */
    public const string HOME = 'app';

    /** The Filament panel id. */
    public function id(): string
    {
        return $this->value;
    }

    /** The URL path the panel is served under. */
    public function path(): string
    {
        return $this->value;
    }

    /** The permission that lets a role enter the workspace. */
    public function permission(): Permission
    {
        return match ($this) {
            self::Sales => Permission::WorkspaceSales,
            self::Tasks => Permission::WorkspaceTasks,
            self::Reports => Permission::WorkspaceReports,
            self::Admin => Permission::WorkspaceAdmin,
        };
    }

    public function getLabel(): string
    {
        return __('enums.workspace_panel.'.$this->value);
    }

    public function getDescription(): string
    {
        return __('enums.workspace_panel_description.'.$this->value);
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Sales => Heroicon::OutlinedBriefcase,
            self::Tasks => Heroicon::OutlinedClipboardDocumentCheck,
            self::Reports => Heroicon::OutlinedChartBar,
            self::Admin => Heroicon::OutlinedCog6Tooth,
        };
    }

    /**
     * Every workspace in display order.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return self::cases();
    }

    /** The workspace behind a panel id; null for the home panel or an unknown id. */
    public static function tryFromId(string $id): ?self
    {
        return self::tryFrom($id);
    }
}
