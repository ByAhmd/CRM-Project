<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\CloseReasonKind;
use App\Enums\CrmRole;
use App\Models\Account;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\Deals\DealCloseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Per-role authorisation matrix of every owned entity (CLAUDE.md section 3,
 * plan section 6; decisions D-3, D-4, D-13, A-10).
 *
 * Each seeded role answers every policy verb twice: on a record inside its
 * reach (owned by a sales rep of the manager's team) and on one outside it
 * (owned by a rep of another team). An actor holds a verb on the in-reach
 * record exactly when its role grants the verb; on the out-of-reach record it
 * additionally needs "all" visibility, which admins, support and read_only
 * have. Class-level abilities (viewAny, create, export, import, the bulk
 * abilities) follow the grants alone, and permanent deletion is refused to
 * everyone.
 *
 * The grants below restate docs/PERMISSIONS.md rather than reading
 * RolePermissionMatrix, so a drift in the seeded defaults, a policy or the
 * resolver fails here. Records are never soft-deleted or closed unless a test
 * says so: a trashed record, a converted lead and a closed deal refuse writes
 * by design and are covered by their own tests.
 */
final class OwnedPolicyMatrixTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    /** Roles whose visibility is "all" and so reach the out-of-reach record too (D-4). */
    private const ALL_VISIBILITY = [CrmRole::SuperAdmin, CrmRole::Admin, CrmRole::Support, CrmRole::ReadOnly];

    /** @var array<string, User> keyed by CrmRole value */
    private array $actors = [];

    private User $rep;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $team = $this->makeTeam();
        $this->rep = $this->salesRep($team);
        $this->outsider = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));

        $this->actors = [
            CrmRole::SuperAdmin->value => $this->superAdmin(),
            CrmRole::Admin->value => $this->admin(),
            CrmRole::SalesManager->value => $this->salesManager($team),
            CrmRole::SalesRep->value => $this->rep,
            CrmRole::Support->value => $this->support(),
            CrmRole::ReadOnly->value => $this->readOnly(),
        ];
    }

    #[Test]
    public function the_lead_verbs_follow_the_role_grants_and_the_visibility_scope(): void
    {
        $full = ['view', 'update', 'changeStatus', 'convert', 'sendEmail', 'delete', 'restore', 'assign', 'create', 'export', 'import'];

        $this->assertMatrix(
            Lead::factory()->create(['owner_id' => $this->rep->getKey()]),
            Lead::factory()->create(['owner_id' => $this->outsider->getKey()]),
            ['view', 'update', 'changeStatus', 'convert', 'sendEmail', 'delete', 'restore', 'assign'],
            [
                CrmRole::SuperAdmin->value => $full,
                CrmRole::Admin->value => $full,
                CrmRole::SalesManager->value => $full,
                // D-4: no reassignment, no delete of commercial records, no import.
                CrmRole::SalesRep->value => ['view', 'update', 'changeStatus', 'convert', 'sendEmail', 'create', 'export'],
                // D-13: every role exports the commercial entities within its scope.
                CrmRole::Support->value => ['view', 'export'],
                CrmRole::ReadOnly->value => ['view', 'export'],
            ],
        );
    }

    #[Test]
    public function the_contact_verbs_follow_the_role_grants_and_the_visibility_scope(): void
    {
        $full = ['view', 'update', 'sendEmail', 'delete', 'restore', 'assign', 'merge', 'create', 'export', 'import'];

        $this->assertMatrix(
            Contact::factory()->create(['owner_id' => $this->rep->getKey()]),
            Contact::factory()->create(['owner_id' => $this->outsider->getKey()]),
            ['view', 'update', 'sendEmail', 'delete', 'restore', 'assign', 'merge'],
            [
                CrmRole::SuperAdmin->value => $full,
                CrmRole::Admin->value => $full,
                CrmRole::SalesManager->value => $full,
                CrmRole::SalesRep->value => ['view', 'update', 'sendEmail', 'create', 'export'],
                CrmRole::Support->value => ['view', 'export'],
                CrmRole::ReadOnly->value => ['view', 'export'],
            ],
        );
    }

    #[Test]
    public function the_account_verbs_follow_the_role_grants_and_the_visibility_scope(): void
    {
        $full = ['view', 'update', 'delete', 'restore', 'assign', 'merge', 'create', 'export', 'import'];

        $this->assertMatrix(
            Account::factory()->create(['owner_id' => $this->rep->getKey()]),
            Account::factory()->create(['owner_id' => $this->outsider->getKey()]),
            ['view', 'update', 'delete', 'restore', 'assign', 'merge'],
            [
                CrmRole::SuperAdmin->value => $full,
                CrmRole::Admin->value => $full,
                CrmRole::SalesManager->value => $full,
                CrmRole::SalesRep->value => ['view', 'update', 'create', 'export'],
                CrmRole::Support->value => ['view', 'export'],
                CrmRole::ReadOnly->value => ['view', 'export'],
            ],
        );
    }

    #[Test]
    public function the_deal_verbs_follow_the_role_grants_and_the_visibility_scope(): void
    {
        $full = ['view', 'update', 'changeStage', 'close', 'delete', 'restore', 'assign', 'create', 'export', 'import'];

        $this->assertMatrix(
            Deal::factory()->create(['owner_id' => $this->rep->getKey()]),
            Deal::factory()->create(['owner_id' => $this->outsider->getKey()]),
            ['view', 'update', 'changeStage', 'close', 'reopen', 'delete', 'restore', 'assign'],
            [
                CrmRole::SuperAdmin->value => $full,
                CrmRole::Admin->value => $full,
                CrmRole::SalesManager->value => $full,
                CrmRole::SalesRep->value => ['view', 'update', 'changeStage', 'close', 'create', 'export'],
                CrmRole::Support->value => ['view', 'export'],
                CrmRole::ReadOnly->value => ['view', 'export'],
            ],
        );
    }

    #[Test]
    public function a_closed_deal_is_reopened_under_the_close_grant_and_refuses_every_other_workflow_verb(): void
    {
        $inReach = Deal::factory()->create(['owner_id' => $this->rep->getKey()]);
        $outOfReach = Deal::factory()->create(['owner_id' => $this->outsider->getKey()]);
        $admin = $this->actors[CrmRole::Admin->value];

        foreach ([$inReach, $outOfReach] as $deal) {
            app(DealCloseService::class)->lose($deal, $this->reasonOfKind(CloseReasonKind::Lost), $admin);
        }

        $reopeners = ['view', 'reopen'];

        $this->assertMatrix(
            $inReach->refresh(),
            $outOfReach->refresh(),
            ['view', 'reopen', 'update', 'changeStage', 'close'],
            [
                CrmRole::SuperAdmin->value => $reopeners,
                CrmRole::Admin->value => $reopeners,
                CrmRole::SalesManager->value => $reopeners,
                CrmRole::SalesRep->value => $reopeners,
                CrmRole::Support->value => ['view'],
                CrmRole::ReadOnly->value => ['view'],
            ],
            classAbilities: [],
        );
    }

    #[Test]
    public function the_task_verbs_follow_the_role_grants_and_the_visibility_scope(): void
    {
        $full = ['view', 'update', 'complete', 'cancel', 'reopen', 'delete', 'restore', 'assign', 'create', 'export'];

        $this->assertMatrix(
            Task::factory()->create(['assignee_id' => $this->rep->getKey()]),
            Task::factory()->create(['assignee_id' => $this->outsider->getKey()]),
            ['view', 'update', 'complete', 'cancel', 'reopen', 'delete', 'restore', 'assign'],
            [
                CrmRole::SuperAdmin->value => $full,
                CrmRole::Admin->value => $full,
                CrmRole::SalesManager->value => $full,
                // Restoring a task is granted with task.delete (there is no task.restore key).
                CrmRole::SalesRep->value => ['view', 'update', 'complete', 'cancel', 'reopen', 'delete', 'restore', 'create', 'export'],
                // Support logs and works tasks everywhere but never deletes, reassigns or exports them.
                CrmRole::Support->value => ['view', 'update', 'complete', 'cancel', 'reopen', 'create'],
                CrmRole::ReadOnly->value => ['view'],
            ],
            classAbilities: ['viewAny', 'create', 'export', 'deleteAny', 'restoreAny'],
        );

        foreach ($this->actors as $role => $actor) {
            $this->assertFalse($actor->can('import', Task::class), "{$role} must not import tasks: there is no task importer");
        }
    }

    #[Test]
    public function the_activity_verbs_follow_the_role_grants_and_activities_stay_immutable(): void
    {
        $this->assertMatrix(
            Activity::factory()->create([
                'lead_id' => Lead::factory()->create(['owner_id' => $this->rep->getKey()])->getKey(),
                'owner_id' => $this->rep->getKey(),
            ]),
            Activity::factory()->create([
                'lead_id' => Lead::factory()->create(['owner_id' => $this->outsider->getKey()])->getKey(),
                'owner_id' => $this->outsider->getKey(),
            ]),
            // A-10: activities are immutable events and hard-deleted, so update and restore are refused to everyone.
            ['view', 'update', 'delete', 'restore'],
            [
                CrmRole::SuperAdmin->value => ['view', 'delete', 'create', 'export'],
                CrmRole::Admin->value => ['view', 'delete', 'create', 'export'],
                CrmRole::SalesManager->value => ['view', 'delete', 'create', 'export'],
                CrmRole::SalesRep->value => ['view', 'create', 'export'],
                CrmRole::Support->value => ['view', 'create'],
                CrmRole::ReadOnly->value => ['view'],
            ],
            classAbilities: ['viewAny', 'create', 'export', 'deleteAny', 'restoreAny'],
        );

        foreach ($this->actors as $role => $actor) {
            $this->assertFalse($actor->can('import', Activity::class), "{$role} must not import activities: there is no activity importer");
        }
    }

    /**
     * Asserts every record verb on both records and every class ability for
     * every role, then that nobody may delete permanently.
     *
     * The class abilities map onto the grant they depend on: viewAny on
     * `view`, deleteAny on `delete`, restoreAny on `restore`; the others on
     * themselves.
     *
     * @param  list<string>  $recordVerbs
     * @param  array<string, list<string>>  $grants  keyed by CrmRole value
     * @param  list<string>  $classAbilities
     */
    private function assertMatrix(
        Model $inReach,
        Model $outOfReach,
        array $recordVerbs,
        array $grants,
        array $classAbilities = ['viewAny', 'create', 'export', 'import', 'deleteAny', 'restoreAny'],
    ): void {
        $entity = class_basename($inReach);
        $grantFor = ['viewAny' => 'view', 'deleteAny' => 'delete', 'restoreAny' => 'restore'];

        $this->assertSame(array_keys($this->actors), array_keys($grants), 'the matrix must list every seeded role');

        foreach ($this->actors as $role => $actor) {
            $reachesEverything = in_array(CrmRole::from($role), self::ALL_VISIBILITY, true);

            foreach ($recordVerbs as $verb) {
                $granted = in_array($verb, $grants[$role], true);

                $this->assertSame($granted, $actor->can($verb, $inReach), sprintf('%s %s %s in reach', $role, $granted ? 'should hold' : 'must not hold', "{$entity}::{$verb}"));
                $this->assertSame($granted && $reachesEverything, $actor->can($verb, $outOfReach), sprintf('%s %s %s out of reach', $role, $granted && $reachesEverything ? 'should hold' : 'must not hold', "{$entity}::{$verb}"));
            }

            foreach ($classAbilities as $ability) {
                $granted = in_array($grantFor[$ability] ?? $ability, $grants[$role], true);

                $this->assertSame($granted, $actor->can($ability, $inReach::class), sprintf('%s %s %s', $role, $granted ? 'should hold' : 'must not hold', "{$entity}::{$ability}"));
            }

            // D-13: soft-deleted records are kept; nobody deletes permanently.
            $this->assertFalse($actor->can('forceDelete', $inReach), "{$role} must not force-delete {$entity}");
            $this->assertFalse($actor->can('forceDeleteAny', $inReach::class), "{$role} must not force-delete any {$entity}");
        }
    }
}
