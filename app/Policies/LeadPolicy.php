<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Lead;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Leads (D-4, D-7, D-13): permission + visibility scope through ChecksPermissions,
 * plus the lead verbs. A converted lead can no longer be edited, re-statused
 * or converted again; a soft-deleted lead accepts no write but restore (D-13).
 */
final class LeadPolicy
{
    use ChecksPermissions;

    protected function permissionGroup(): string
    {
        return Lead::permissionGroup();
    }

    public function update(User $user, ?Lead $lead = null): bool
    {
        if ($lead?->isConverted() === true) {
            return false;
        }

        return ! $this->isTrashed($lead)
            && $user->can($this->permission('update'))
            && $this->reaches($user, $lead);
    }

    public function changeStatus(User $user, ?Lead $lead = null): bool
    {
        if ($lead?->isConverted() === true) {
            return false;
        }

        return $this->verb($user, 'change_status', $lead);
    }

    public function convert(User $user, ?Lead $lead = null): bool
    {
        if ($lead?->isConverted() === true) {
            return false;
        }

        return $this->verb($user, 'convert', $lead);
    }

    public function assign(User $user, ?Lead $lead = null): bool
    {
        return $this->verb($user, 'assign', $lead);
    }

    /** Sending a templated email (D-10): the cross-cutting `email.send` permission plus reach over the record. */
    public function sendEmail(User $user, ?Lead $lead = null): bool
    {
        return ! $this->isTrashed($lead)
            && $user->can(Permission::EmailSend->value)
            && $this->reaches($user, $lead);
    }

    public function export(User $user): bool
    {
        return $user->can($this->permission('export'));
    }

    public function import(User $user): bool
    {
        return $user->can($this->permission('import'));
    }
}
