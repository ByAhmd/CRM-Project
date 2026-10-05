<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Enums\WorkspacePanel;
use App\Models\User;
use App\Services\Access\PanelAccess;
use App\Support\Filament\PanelUrl;

/**
 * Every address under /admin that moved when the CRM split into workspaces
 * (decision D-23), mapped to its new home: the path below /admin that
 * moved, and the path it lives under now. Each answers a permanent redirect
 * to the same place with whatever followed it in the path and the query
 * string kept (routes/web.php, LegacyAdminRedirectController) — stored
 * notifications, sent e-mails and bookmarks carry these addresses and are
 * never rewritten.
 *
 * The list is explicit: an address the Administration workspace still
 * serves (users, roles, teams, settings, lookups, pipelines, custom fields,
 * scoring rules, the audit log, backups) is never shadowed. The bare /admin
 * — the former dashboard — is the Administration home now, the one moved
 * address that cannot answer a permanent redirect (D-23 records the
 * exception): see formerDashboard().
 */
final class LegacyAdminRedirects
{
    /**
     * Where the bare /admin sends a user who may not enter Administration
     * (AuthenticateWorkspace, and the login response for a followed link):
     * the sales dashboard — the page that address used to show — with the
     * query string (its filters) kept, when the user may enter Sales; the
     * workspace screen otherwise. The answer depends on who asks, so it is a
     * temporary redirect, never a permanent one.
     */
    public static function formerDashboard(User $user, ?string $query): string
    {
        if (! app(PanelAccess::class)->canAccess($user, WorkspacePanel::Sales)) {
            return PanelUrl::workspaceScreen();
        }

        return PanelUrl::panelHome(WorkspacePanel::Sales).($query === null || $query === '' ? '' : '?'.$query);
    }

    /**
     * Old path below /admin => new path below the site root.
     *
     * @return array<string, string>
     */
    public static function map(): array
    {
        $sales = WorkspacePanel::Sales->path();
        $tasks = WorkspacePanel::Tasks->path();
        $reports = WorkspacePanel::Reports->path();
        $home = WorkspacePanel::HOME;

        $map = [];

        foreach (['leads', 'deals', 'products', 'contacts', 'accounts', 'activities', 'email-templates', 'imports', 'exports', 'deal-board'] as $slug) {
            $map[$slug] = $sales.'/'.$slug;
        }

        foreach (['tasks', 'task-templates', 'tasks-board', 'calendar'] as $slug) {
            $map[$slug] = $tasks.'/'.$slug;
        }

        foreach (['leads', 'funnel', 'pipeline', 'sales', 'activities', 'sources', 'win-loss', 'forecast', 'tasks'] as $report) {
            $map['reports/'.$report] = $reports.'/'.$report;
        }

        foreach (['login', 'password-reset', 'profile', 'notification-preferences'] as $slug) {
            $map[$slug] = $home.'/'.$slug;
        }

        return $map;
    }
}
