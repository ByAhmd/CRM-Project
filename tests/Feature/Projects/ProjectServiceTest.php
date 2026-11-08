<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\CloseReasonKind;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Project;
use App\Services\Deals\DealCloseService;
use App\Services\Projects\ProjectService;
use Database\Seeders\ProjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The project rules (decision D-30): exactly one default at all times, the
 * default neither unset nor deactivated nor deleted, a project in use never
 * deleted, and the card figures counted inside the viewer's visibility (D-4).
 */
final class ProjectServiceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private ProjectService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();

        $this->service = app(ProjectService::class);
    }

    #[Test]
    public function the_release_project_is_the_default_and_a_new_project_is_not(): void
    {
        $default = $this->defaultProject();

        $this->assertSame(ProjectSeeder::NAME_EN, $default->name_en);
        $this->assertTrue($default->is_default);

        $iqan = $this->service->create(['name_ar' => 'إيقان', 'name_en' => 'Iqan', 'is_active' => true]);

        $this->assertFalse($iqan->is_default);
        $this->assertTrue($iqan->is_active);
        $this->assertSame(1, Project::query()->where('is_default', true)->count());
        $this->assertTrue($default->refresh()->is_default);
    }

    #[Test]
    public function making_a_project_the_default_unsets_the_previous_default(): void
    {
        $default = $this->defaultProject();

        $iqan = $this->service->create(['name_ar' => 'إيقان', 'name_en' => 'Iqan', 'is_default' => true]);

        $this->assertTrue($iqan->is_default);
        $this->assertFalse($default->refresh()->is_default);
        $this->assertSame(1, Project::query()->where('is_default', true)->count());

        $this->service->update($default, ['is_default' => true]);

        $this->assertTrue($default->refresh()->is_default);
        $this->assertFalse($iqan->refresh()->is_default);
        $this->assertSame(1, Project::query()->where('is_default', true)->count());
    }

    #[Test]
    public function the_only_default_cannot_be_unset(): void
    {
        $default = $this->defaultProject();

        try {
            $this->service->update($default, ['is_default' => false]);
            $this->fail('unsetting the only default must be refused');
        } catch (ValidationException $exception) {
            $this->assertSame(['is_default' => [__('projects.validation.last_default')]], $exception->errors());
        }

        $this->assertTrue($default->refresh()->is_default);
    }

    #[Test]
    public function the_default_cannot_be_deactivated(): void
    {
        $default = $this->defaultProject();

        try {
            $this->service->update($default, ['is_active' => false, 'name_en' => 'Renamed']);
            $this->fail('deactivating the default must be refused');
        } catch (ValidationException $exception) {
            $this->assertSame(['is_active' => [__('projects.validation.default_must_be_active')]], $exception->errors());
        }

        $default->refresh();
        $this->assertTrue($default->is_active);
        $this->assertSame(ProjectSeeder::NAME_EN, $default->name_en, 'the whole update is refused, not only the flag');
    }

    #[Test]
    public function a_project_may_be_made_the_default_only_while_active(): void
    {
        $old = $this->makeProject('Old', 'قديم', ['is_active' => false]);

        try {
            $this->service->update($old, ['is_default' => true]);
            $this->fail('an inactive project must not become the default');
        } catch (ValidationException $exception) {
            $this->assertSame(['is_default' => [__('projects.validation.default_must_be_active')]], $exception->errors());
        }

        $this->assertFalse($old->refresh()->is_default);

        try {
            $this->service->create(['name_ar' => 'جديد', 'name_en' => 'New', 'is_active' => false, 'is_default' => true]);
            $this->fail('an inactive project must not be created as the default');
        } catch (ValidationException $exception) {
            $this->assertSame(['is_default' => [__('projects.validation.default_must_be_active')]], $exception->errors());
        }

        $this->assertDatabaseMissing('projects', ['name_en' => 'New']);
        $this->assertTrue($this->defaultProject()->is_default);
    }

    #[Test]
    public function the_first_project_ever_created_becomes_the_default(): void
    {
        Project::query()->delete();

        $first = $this->service->create(['name_ar' => 'الأول', 'name_en' => 'First']);

        $this->assertTrue($first->is_default, 'the invariant holds from the first row');
        $this->assertSame(1, Project::query()->where('is_default', true)->count());
    }

    #[Test]
    public function the_default_and_a_project_in_use_are_never_deleted_and_an_unused_one_is(): void
    {
        $default = $this->defaultProject();
        $iqan = $this->makeProject();
        $spare = $this->makeProject('Spare', 'احتياطي');
        $lead = Lead::factory()->create(['project_id' => $iqan->getKey()]);

        try {
            $this->service->delete($default);
            $this->fail('the default project must never be deleted');
        } catch (ValidationException $exception) {
            $this->assertSame(['is_default' => [__('projects.validation.last_default')]], $exception->errors());
        }

        // A soft-deleted record still holds its project (the RESTRICT key counts it).
        $lead->delete();

        $this->assertTrue($this->service->isInUse($iqan));

        try {
            $this->service->delete($iqan);
            $this->fail('a project holding records must never be deleted');
        } catch (ValidationException $exception) {
            $this->assertSame(['is_active' => [__('projects.validation.in_use')]], $exception->errors());
        }

        $this->assertDatabaseHas('projects', ['id' => $default->getKey()]);
        $this->assertDatabaseHas('projects', ['id' => $iqan->getKey()]);

        $this->assertFalse($this->service->isInUse($spare));
        $this->service->delete($spare);

        $this->assertDatabaseMissing('projects', ['id' => $spare->getKey()]);
    }

    #[Test]
    public function the_counts_follow_the_viewers_visibility_and_sum_the_open_deals_only(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $teammate = $this->salesRep($team);
        $outsider = $this->salesRep();
        $superAdmin = $this->superAdmin();
        $main = $this->defaultProject();
        $iqan = $this->makeProject();
        $ids = [(int) $main->getKey(), (int) $iqan->getKey()];

        Lead::factory()->create(['owner_id' => $teammate->getKey(), 'project_id' => $main->getKey()]);
        Lead::factory()->create(['owner_id' => $outsider->getKey(), 'project_id' => $main->getKey()]);
        Lead::factory()->create(['owner_id' => $teammate->getKey(), 'project_id' => $iqan->getKey()]);
        Contact::factory()->create(['owner_id' => $teammate->getKey(), 'project_id' => $main->getKey()]);
        Account::factory()->create(['owner_id' => $outsider->getKey(), 'project_id' => $main->getKey()]);

        // Three deals in the main project, each with an unowned account of its own:
        // two open (1000 by the teammate, 2500 by the outsider) and one won (999).
        Deal::factory()->create(['owner_id' => $teammate->getKey(), 'project_id' => $main->getKey(), 'amount' => 1000]);
        Deal::factory()->create(['owner_id' => $outsider->getKey(), 'project_id' => $main->getKey(), 'amount' => 2500]);
        $won = Deal::factory()->create(['owner_id' => $teammate->getKey(), 'project_id' => $main->getKey(), 'amount' => 999]);
        app(DealCloseService::class)->win($won, $this->reasonOfKind(CloseReasonKind::Won), $superAdmin);

        // Everything: 4 accounts in the main project (the outsider's and the three the deals made).
        $all = $this->service->countsFor($superAdmin, $ids);

        $this->assertSame(['leads_count' => 2, 'contacts_count' => 1, 'accounts_count' => 4, 'deals_count' => 3, 'open_deals_count' => 2, 'open_deals_amount' => 3500.0], $all[$ids[0]]);
        $this->assertSame(['leads_count' => 1, 'contacts_count' => 0, 'accounts_count' => 0, 'deals_count' => 0, 'open_deals_count' => 0, 'open_deals_amount' => 0.0], $all[$ids[1]]);

        // The team: the teammate's records and the unowned accounts, never the outsider's.
        $teamScope = $this->service->countsFor($manager, $ids);

        $this->assertSame(['leads_count' => 1, 'contacts_count' => 1, 'accounts_count' => 3, 'deals_count' => 2, 'open_deals_count' => 1, 'open_deals_amount' => 1000.0], $teamScope[$ids[0]]);
        $this->assertSame(1, $teamScope[$ids[1]]['leads_count']);

        // Own records only: the outsider's lead, account and deal in the main project, nothing in Iqan.
        $own = $this->service->countsFor($outsider, $ids);

        $this->assertSame(['leads_count' => 1, 'contacts_count' => 0, 'accounts_count' => 1, 'deals_count' => 1, 'open_deals_count' => 1, 'open_deals_amount' => 2500.0], $own[$ids[0]]);
        $this->assertSame(['leads_count' => 0, 'contacts_count' => 0, 'accounts_count' => 0, 'deals_count' => 0, 'open_deals_count' => 0, 'open_deals_amount' => 0.0], $own[$ids[1]]);

        // A reader with no commercial reach at all sees zeros, not an error.
        $employee = $this->employee();

        $this->assertSame(0, $this->service->countsFor($employee, $ids)[$ids[0]]['leads_count']);
        $this->assertSame([], $this->service->countsFor($employee, []));
    }
}
