<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Enums\CloseReasonKind;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Services\Deals\DealCloseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The per-role reach of DealPolicy (decisions D-3, D-4): a rep reaches only
 * their own deals, a manager their team's, admins everything; reopening
 * follows the same reach as closing.
 */
final class DealPolicyTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function a_rep_reaches_only_their_own_deals(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $mine = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $theirs = Deal::factory()->create(['owner_id' => $other->getKey()]);

        foreach (['view', 'update', 'changeStage', 'close'] as $ability) {
            $this->assertTrue($rep->can($ability, $mine), $ability.' on own deal');
            $this->assertFalse($rep->can($ability, $theirs), $ability.' on another rep\'s deal');
        }

        // Reps never reassign (D-4) and only a closed deal can be reopened.
        $this->assertFalse($rep->can('assign', $mine));
        $this->assertFalse($rep->can('assign', $theirs));
        $this->assertFalse($rep->can('reopen', $mine));
        $this->assertFalse($rep->can('reopen', $theirs));
        $this->assertFalse($rep->can('delete', $mine));

        app(DealCloseService::class)->win($theirs, $this->reasonOfKind(CloseReasonKind::Won), $other);

        $this->assertTrue($other->can('reopen', $theirs));
        $this->assertFalse($rep->can('reopen', $theirs));
        $this->assertFalse($rep->can('view', $theirs));
    }

    #[Test]
    public function a_manager_reaches_the_team_s_deals_but_not_another_team_s(): void
    {
        $team = $this->makeTeam();
        $otherTeam = $this->makeTeam('Jeddah Team', 'فريق جدة');
        $manager = $this->salesManager($team);
        $member = $this->salesRep($team);
        $teammate = $this->salesRep($team);
        $outsider = $this->salesRep($otherTeam);

        $inTeam = Deal::factory()->create(['owner_id' => $member->getKey()]);
        $teammates = Deal::factory()->create(['owner_id' => $teammate->getKey()]);
        $outside = Deal::factory()->create(['owner_id' => $outsider->getKey()]);

        foreach (['view', 'update', 'changeStage', 'close', 'assign', 'delete'] as $ability) {
            $this->assertTrue($manager->can($ability, $inTeam), $ability.' on a team member\'s deal');
            $this->assertFalse($manager->can($ability, $outside), $ability.' on another team\'s deal');
        }

        // Team membership widens the manager's reach only, not a teammate's.
        $this->assertTrue($teammate->can('view', $teammates));
        $this->assertTrue($teammate->can('update', $teammates));
        $this->assertFalse($teammate->can('view', $inTeam));
        $this->assertFalse($teammate->can('update', $inTeam));
        $this->assertFalse($teammate->can('changeStage', $inTeam));

        app(DealCloseService::class)->lose($outside, $this->reasonOfKind(CloseReasonKind::Lost), $outsider, 'Went with a competitor');

        $this->assertFalse($manager->can('reopen', $outside));
        $this->assertFalse($manager->can('update', $outside));

        $admin = $this->admin();
        $readOnly = $this->readOnly();

        $this->assertTrue($admin->can('view', $outside));
        $this->assertTrue($admin->can('reopen', $outside));
        $this->assertTrue($admin->can('update', $inTeam));
        $this->assertTrue($readOnly->can('view', $outside));
        $this->assertFalse($readOnly->can('update', $inTeam));
        $this->assertFalse($readOnly->can('changeStage', $inTeam));
        $this->assertFalse($readOnly->can('reopen', $outside));
    }

    private function reasonOfKind(CloseReasonKind $kind): DealCloseReason
    {
        return DealCloseReason::query()->where('kind', $kind->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
    }
}
