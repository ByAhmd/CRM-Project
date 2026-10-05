<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Enums\WorkspacePanel;
use App\Models\User;
use Filament\Panel;

/**
 * Who may enter which panel (decision D-23) — the single place the rule
 * lives; User::canAccessPanel() delegates here, so Filament's panel
 * middleware, the workspace screen, the switcher, the login response and
 * every cross-workspace link ask the same question.
 *
 * - The home panel (sign-in, password reset, profile, notification
 *   preferences, the workspace screen) admits every user who may sign in
 *   (D-11): an active account.
 * - A workspace admits a user who may sign in AND holds its
 *   `workspace.<id>` permission (spatie, D-3). Inside it every page keeps
 *   its own permission and record scope.
 * - Anything else — an unknown panel id, a disabled or pending account —
 *   is refused: the rule fails closed.
 */
final class PanelAccess
{
    public function canAccess(User $user, Panel|WorkspacePanel|string $panel): bool
    {
        if (! $user->status->canAuthenticate()) {
            return false;
        }

        $id = match (true) {
            $panel instanceof Panel => $panel->getId(),
            $panel instanceof WorkspacePanel => $panel->id(),
            default => $panel,
        };

        if ($id === WorkspacePanel::HOME) {
            return true;
        }

        $workspace = WorkspacePanel::tryFromId($id);

        if ($workspace === null) {
            return false;
        }

        return $user->can($workspace->permission()->value);
    }

    /**
     * The workspaces the user may enter, in workspace order.
     *
     * @return list<WorkspacePanel>
     */
    public function enterableWorkspaces(User $user): array
    {
        return array_values(array_filter(
            WorkspacePanel::ordered(),
            fn (WorkspacePanel $workspace): bool => $this->canAccess($user, $workspace),
        ));
    }
}
