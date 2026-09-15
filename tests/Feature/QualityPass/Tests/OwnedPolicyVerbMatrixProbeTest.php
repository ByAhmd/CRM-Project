<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Tests;

use App\Models\Account;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Test-suite audit probe (plan section 6, "Authorization: per-role matrices"):
 * every policy verb of every owned entity answered once positively and once
 * negatively. The existing suite covers Deal loops and a handful of verbs per
 * entity; Contact assign/delete/restore/update, Lead delete/restore/assign,
 * Account restore, export/import verbs and Task cancel/reopen have no policy
 * assertion anywhere under tests/Feature.
 */
final class OwnedPolicyVerbMatrixProbeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Team $team;

    private User $manager;

    private User $rep;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $this->team = $this->makeTeam();
        $this->manager = $this->salesManager($this->team);
        $this->rep = $this->salesRep($this->team);
        $this->outsider = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));
    }

    #[Test]
    public function every_lead_verb_has_a_positive_and_a_negative_answer(): void
    {
        $mine = Lead::factory()->create(['owner_id' => $this->rep->getKey()]);
        $outside = Lead::factory()->create(['owner_id' => $this->outsider->getKey()]);

        $this->assertVerbs($mine, $outside, [
            'view' => [$this->rep],
            'update' => [$this->rep],
            'changeStatus' => [$this->rep],
            'convert' => [$this->rep],
            'sendEmail' => [$this->rep],
            'delete' => [$this->manager],
            'restore' => [$this->manager],
            'assign' => [$this->manager],
        ]);

        $this->assertFalse($this->rep->can('delete', $mine), 'D-4 table: reps never delete leads');
        $this->assertFalse($this->rep->can('restore', $mine));
        $this->assertFalse($this->rep->can('assign', $mine), 'D-4: reps never reassign');
        $this->assertFalse($this->admin()->can('forceDelete', $mine), 'D-13');
        $this->assertTrue($this->rep->can('export', Lead::class));
        $this->assertFalse($this->rep->can('import', Lead::class));
        $this->assertTrue($this->manager->can('import', Lead::class));
        // D-13 grants {entity}.export on the commercial entities to every role, read_only included
        // (exporters stay inside the visibility scope); the probe originally asserted the pre-fix matrix.
        $this->assertTrue($this->readOnly()->can('export', Lead::class));
    }

    #[Test]
    public function every_contact_verb_has_a_positive_and_a_negative_answer(): void
    {
        $mine = Contact::factory()->create(['owner_id' => $this->rep->getKey()]);
        $outside = Contact::factory()->create(['owner_id' => $this->outsider->getKey()]);

        $this->assertVerbs($mine, $outside, [
            'view' => [$this->rep],
            'update' => [$this->rep],
            'sendEmail' => [$this->rep],
            'delete' => [$this->manager],
            'restore' => [$this->manager],
            'assign' => [$this->manager],
            'merge' => [$this->manager],
        ]);

        $this->assertFalse($this->rep->can('delete', $mine));
        $this->assertFalse($this->rep->can('assign', $mine));
        $this->assertFalse($this->rep->can('merge', $mine));
        $this->assertTrue($this->rep->can('export', Contact::class));
        $this->assertFalse($this->rep->can('import', Contact::class));
        $this->assertTrue($this->manager->can('import', Contact::class));
    }

    #[Test]
    public function every_account_verb_has_a_positive_and_a_negative_answer(): void
    {
        $mine = Account::factory()->create(['owner_id' => $this->rep->getKey()]);
        $outside = Account::factory()->create(['owner_id' => $this->outsider->getKey()]);

        $this->assertVerbs($mine, $outside, [
            'view' => [$this->rep],
            'update' => [$this->rep],
            'delete' => [$this->manager],
            'restore' => [$this->manager],
            'assign' => [$this->manager],
            'merge' => [$this->manager],
        ]);

        $this->assertFalse($this->rep->can('restore', $mine));
        $this->assertFalse($this->rep->can('merge', $mine));
        $this->assertTrue($this->rep->can('export', Account::class));
        $this->assertFalse($this->rep->can('import', Account::class));
    }

    #[Test]
    public function every_deal_verb_has_a_positive_and_a_negative_answer(): void
    {
        $mine = Deal::factory()->create(['owner_id' => $this->rep->getKey()]);
        $outside = Deal::factory()->create(['owner_id' => $this->outsider->getKey()]);

        $this->assertVerbs($mine, $outside, [
            'view' => [$this->rep],
            'update' => [$this->rep],
            'changeStage' => [$this->rep],
            'close' => [$this->rep],
            'delete' => [$this->manager],
            'restore' => [$this->manager],
            'assign' => [$this->manager],
        ]);

        $this->assertFalse($this->rep->can('restore', $mine));
        $this->assertTrue($this->rep->can('export', Deal::class));
        $this->assertFalse($this->rep->can('import', Deal::class));
        $this->assertTrue($this->manager->can('import', Deal::class));
    }

    #[Test]
    public function every_task_verb_has_a_positive_and_a_negative_answer(): void
    {
        $mine = Task::factory()->create(['assignee_id' => $this->rep->getKey()]);
        $outside = Task::factory()->create(['assignee_id' => $this->outsider->getKey()]);

        $this->assertVerbs($mine, $outside, [
            'view' => [$this->rep],
            'update' => [$this->rep],
            'complete' => [$this->rep],
            'cancel' => [$this->rep],
            'delete' => [$this->rep],
            'restore' => [$this->rep],
            'assign' => [$this->manager],
        ]);

        app(TaskService::class)->cancel($mine, $this->rep);
        $this->assertTrue($this->rep->can('reopen', $mine->refresh()));
        $this->assertFalse($this->outsider->can('reopen', $mine));
        $this->assertFalse($this->readOnly()->can('cancel', $mine));
        $this->assertTrue($this->rep->can('export', Task::class));
        $this->assertFalse($this->readOnly()->can('export', Task::class));
    }

    #[Test]
    public function every_activity_verb_has_a_positive_and_a_negative_answer(): void
    {
        $mine = Activity::factory()->create([
            'lead_id' => Lead::factory()->create(['owner_id' => $this->rep->getKey()])->getKey(),
            'owner_id' => $this->rep->getKey(),
        ]);
        $outside = Activity::factory()->create([
            'lead_id' => Lead::factory()->create(['owner_id' => $this->outsider->getKey()])->getKey(),
            'owner_id' => $this->outsider->getKey(),
        ]);

        $this->assertVerbs($mine, $outside, [
            'view' => [$this->rep],
            'delete' => [$this->manager],
        ]);

        $this->assertFalse($this->admin()->can('update', $mine), 'activities are immutable (A-10)');
        $this->assertFalse($this->admin()->can('restore', $mine));
        $this->assertTrue($this->rep->can('create', Activity::class));
        $this->assertFalse($this->readOnly()->can('create', Activity::class));
        $this->assertTrue($this->rep->can('export', Activity::class));
        $this->assertFalse($this->readOnly()->can('export', Activity::class));
    }

    /**
     * For each verb: every listed actor holds it on the in-reach record and is
     * refused on the out-of-reach one; read_only is refused every write verb.
     *
     * @param  array<string, list<User>>  $verbs
     */
    private function assertVerbs(Model $inReach, Model $outOfReach, array $verbs): void
    {
        $readOnly = $this->readOnly();

        foreach ($verbs as $verb => $actors) {
            foreach ($actors as $actor) {
                $this->assertTrue($actor->can($verb, $inReach), sprintf('%s should hold %s on %s in reach', $actor->name, $verb, $inReach::class));
                $this->assertFalse($actor->can($verb, $outOfReach), sprintf('%s must not hold %s on %s out of reach', $actor->name, $verb, $outOfReach::class));
            }

            if ($verb !== 'view') {
                $this->assertFalse($readOnly->can($verb, $inReach), sprintf('read_only must not hold %s on %s', $verb, $inReach::class));
            }
        }
    }
}
