<?php

declare(strict_types=1);

namespace App\Support\Landing;

use App\Enums\CrmRole;
use App\Enums\WorkspacePanel;

/**
 * The facts strip of the landing page (decision D-24). Every number is read
 * from the code or the configuration at render time, never written in the
 * copy, so the page cannot drift from what the CRM does: a fifth workspace,
 * an eighth seeded role, a third interface language or a changed audit
 * retention shows up here by itself.
 */
final class LandingFacts
{
    /**
     * @return list<LandingFact>
     */
    public static function all(): array
    {
        return [
            new LandingFact('workspaces', count(WorkspacePanel::cases())),
            new LandingFact('roles', count(CrmRole::cases())),
            new LandingFact('languages', count((array) config('app.locales'))),
            new LandingFact('audit_days', (int) config('crm.audit.retention_days')),
        ];
    }
}
