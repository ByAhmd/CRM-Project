<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\CrmRole;
use App\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The owner's rules (D-3, D-4, D-13) as the seeded roles actually answer them.
 */
final class PermissionMatrixTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
    }

    #[Test]
    public function a_sales_rep_sees_own_records_only_and_cannot_reassign_import_or_delete_leads(): void
    {
        $rep = $this->salesRep();

        $this->assertTrue($rep->can(Permission::LeadViewAny->value));
        $this->assertFalse($rep->can(Permission::LeadViewTeam->value));
        $this->assertFalse($rep->can(Permission::LeadViewAll->value));
        $this->assertFalse($rep->can(Permission::LeadAssign->value), 'D-4: reps may not reassign');
        $this->assertFalse($rep->can(Permission::LeadImport->value));
        $this->assertFalse($rep->can(Permission::LeadDelete->value));
        $this->assertFalse($rep->can(Permission::AccountSetType->value), 'D-6: a prospect becomes a customer through its first won deal');
        $this->assertFalse($rep->can(Permission::ActivityAssign->value), 'D-4: reps may not reassign');
        $this->assertTrue($rep->can(Permission::LeadExport->value), 'D-13: reps export within their scope');
        $this->assertTrue($rep->can(Permission::LeadConvert->value));
    }

    #[Test]
    public function a_sales_manager_sees_the_team_but_not_everything_and_may_reassign(): void
    {
        $manager = $this->salesManager();

        $this->assertTrue($manager->can(Permission::DealViewTeam->value));
        $this->assertFalse($manager->can(Permission::DealViewAll->value));
        $this->assertTrue($manager->can(Permission::DealAssign->value));
        $this->assertTrue($manager->can(Permission::LeadImport->value));
        $this->assertTrue($manager->can(Permission::ActivityAssign->value));
        $this->assertFalse($manager->can(Permission::AccountSetType->value), 'D-6: only admins set the account type by hand');
        $this->assertFalse($manager->can(Permission::UsersManage->value));
        $this->assertFalse($manager->can(Permission::SettingsManage->value));
        $this->assertFalse($manager->can(Permission::AuditView->value));
    }

    #[Test]
    public function support_reads_everything_and_logs_interactions_but_never_changes_commercial_records(): void
    {
        $support = $this->support();

        $this->assertTrue($support->can(Permission::LeadViewAll->value));
        $this->assertTrue($support->can(Permission::ActivityCreate->value));
        $this->assertTrue($support->can(Permission::TaskCreate->value));
        $this->assertFalse($support->can(Permission::LeadCreate->value));
        $this->assertFalse($support->can(Permission::DealUpdate->value));
        $this->assertFalse($support->can(Permission::AccountSetType->value));
    }

    #[Test]
    public function read_only_has_no_write_permission_at_all(): void
    {
        $readOnly = $this->readOnly();

        $this->assertTrue($readOnly->can(Permission::AccountViewAll->value));

        foreach (Permission::cases() as $permission) {
            // Exporting is reading (D-13): read_only exports what it may see.
            if (in_array($permission->verb(), ['view_any', 'view_team', 'view_all', 'view', 'download', 'export'], true)) {
                continue;
            }

            $this->assertFalse($readOnly->can($permission->value), "read_only must not hold {$permission->value}");
        }
    }

    #[Test]
    public function admin_manages_users_settings_and_audit_but_not_roles(): void
    {
        $admin = $this->admin();

        $this->assertTrue($admin->can(Permission::UsersManage->value));
        $this->assertTrue($admin->can(Permission::SettingsManage->value));
        $this->assertTrue($admin->can(Permission::AuditView->value));
        $this->assertTrue($admin->can(Permission::LeadViewAll->value));
        $this->assertTrue($admin->can(Permission::AccountSetType->value), 'D-6: admins may set the account type manually');
        $this->assertTrue($admin->can(Permission::ActivityAssign->value));
        $this->assertFalse($admin->can(Permission::RolesManage->value));
    }

    #[Test]
    public function every_seeded_role_exports_the_four_commercial_entities_within_its_scope(): void
    {
        // D-13: {entity}.export is granted to every role incl. sales_rep; the
        // exporters apply RecordVisibilityResolver, so the grant never widens reach.
        foreach (CrmRole::cases() as $role) {
            $user = $this->makeUser($role);

            foreach ([Permission::LeadExport, Permission::ContactExport, Permission::AccountExport, Permission::DealExport] as $permission) {
                $this->assertTrue($user->can($permission->value), "{$role->value} lacks {$permission->value}");
            }
        }
    }

    #[Test]
    public function super_admin_holds_every_permission(): void
    {
        $superAdmin = $this->superAdmin();

        foreach (Permission::cases() as $permission) {
            $this->assertTrue($superAdmin->can($permission->value), "super_admin lacks {$permission->value}");
        }
    }
}
