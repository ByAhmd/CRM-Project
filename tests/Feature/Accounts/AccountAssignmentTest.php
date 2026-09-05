<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Enums\ActivityLogEvent;
use App\Exceptions\Access\UnassignableUserException;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Notifications\RecordAssignedNotification;
use App\Services\Access\RecordAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Reassignment (D-4): only assign-holders, only within reach, always audited
 * and notified.
 */
final class AccountAssignmentTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
    }

    #[Test]
    public function a_manager_reassigns_within_the_team_and_the_new_owner_is_notified(): void
    {
        Notification::fake();

        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $from = $this->salesRep($team);
        $to = $this->salesRep($team);
        $account = Account::factory()->create(['owner_id' => $from->getKey()]);

        app(RecordAssignmentService::class)->assign($account, $to, $manager);

        $this->assertSame($to->getKey(), $account->refresh()->owner_id);

        $log = ActivityLog::query()->where('description', ActivityLogEvent::AccountAssigned->value)->latest('id')->firstOrFail();
        $this->assertSame($manager->getKey(), (int) $log->causer_id);
        $this->assertSame($from->getKey(), (int) $log->properties->get('previous_owner_id'));
        $this->assertSame($to->getKey(), (int) $log->properties->get('owner_id'));

        Notification::assertSentTo($to, RecordAssignedNotification::class, function (RecordAssignedNotification $notification, array $channels) use ($to): bool {
            $database = $notification->toDatabase($to);

            return $channels === ['database'] && str_contains((string) json_encode($database), 'filament');
        });
    }

    #[Test]
    public function a_manager_cannot_assign_outside_their_team(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $outsider = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);

        $this->expectException(UnassignableUserException::class);
        app(RecordAssignmentService::class)->assign($account, $outsider, $manager);
    }

    #[Test]
    public function a_rep_does_not_see_the_assign_action_but_a_manager_does(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(ListAccounts::class)
            ->assertTableActionHidden('assign', $account);

        Livewire::actingAs($manager)
            ->test(ListAccounts::class)
            ->assertTableActionVisible('assign', $account)
            ->callTableAction('assign', $account, data: ['owner_id' => $manager->getKey()])
            ->assertNotified();

        $this->assertSame($manager->getKey(), $account->refresh()->owner_id);
    }

    #[Test]
    public function bulk_assignment_touches_only_records_the_actor_may_assign(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $member = $this->salesRep($team);
        $outsider = $this->salesRep();
        $admin = $this->admin();

        $inTeam = Account::factory()->create(['owner_id' => $member->getKey()]);
        $outside = Account::factory()->create(['owner_id' => $outsider->getKey()]);

        // The manager may only reassign records inside their reach…
        $this->assertTrue($manager->can('assign', $inTeam));
        $this->assertFalse($manager->can('assign', $outside));

        Livewire::actingAs($manager)
            ->test(ListAccounts::class)
            ->callTableBulkAction('assign', [$inTeam], data: ['owner_id' => $manager->getKey()]);

        $this->assertSame($manager->getKey(), $inTeam->refresh()->owner_id);
        $this->assertSame($outsider->getKey(), $outside->refresh()->owner_id);

        // …while an admin reaches everything.
        Livewire::actingAs($admin)
            ->test(ListAccounts::class)
            ->callTableBulkAction('assign', [$inTeam, $outside], data: ['owner_id' => $member->getKey()]);

        $this->assertSame($member->getKey(), $inTeam->refresh()->owner_id);
        $this->assertSame($member->getKey(), $outside->refresh()->owner_id);
    }

    #[Test]
    public function assigning_to_the_same_owner_is_a_no_op(): void
    {
        $manager = $this->salesManager();
        $account = Account::factory()->create(['owner_id' => $manager->getKey()]);

        app(RecordAssignmentService::class)->assign($account, $manager, $manager);

        $this->assertSame(0, ActivityLog::query()->where('description', ActivityLogEvent::AccountAssigned->value)->count());
    }
}
