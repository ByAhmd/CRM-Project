<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\VisibilityLevel;
use App\Services\Access\RecordVisibilityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Support\OwnedStub;
use Tests\TestCase;

/**
 * Decision D-4: own / team / all resolved from permissions and team membership.
 */
final class RecordVisibilityResolverTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private RecordVisibilityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->resolver = app(RecordVisibilityResolver::class);
    }

    #[Test]
    public function levels_follow_the_seeded_roles(): void
    {
        $this->assertSame(VisibilityLevel::Own, $this->resolver->levelFor($this->salesRep(), OwnedStub::class));
        $this->assertSame(VisibilityLevel::Team, $this->resolver->levelFor($this->salesManager(), OwnedStub::class));
        $this->assertSame(VisibilityLevel::All, $this->resolver->levelFor($this->admin(), OwnedStub::class));
        $this->assertSame(VisibilityLevel::All, $this->resolver->levelFor($this->support(), OwnedStub::class));
        $this->assertSame(VisibilityLevel::All, $this->resolver->levelFor($this->readOnly(), OwnedStub::class));
    }

    #[Test]
    public function a_user_without_any_view_permission_reaches_nothing(): void
    {
        $user = $this->salesRep();
        $user->syncRoles([]);

        $user->refresh();

        $this->assertSame(VisibilityLevel::None, $this->resolver->levelFor($user, OwnedStub::class));
        $this->assertFalse($this->resolver->canRead($user, new OwnedStub(['owner_id' => $user->getKey()])));
    }

    #[Test]
    public function a_rep_reaches_only_records_they_own(): void
    {
        $rep = $this->salesRep();
        $colleague = $this->salesRep();

        $this->assertTrue($this->resolver->canRead($rep, new OwnedStub(['owner_id' => $rep->getKey()])));
        $this->assertFalse($this->resolver->canRead($rep, new OwnedStub(['owner_id' => $colleague->getKey()])));
        $this->assertFalse($this->resolver->canRead($rep, new OwnedStub(['owner_id' => null])), 'unowned records are not visible at own level');
        $this->assertFalse($this->resolver->canWrite($rep, new OwnedStub(['owner_id' => $colleague->getKey()])));
    }

    #[Test]
    public function a_manager_reaches_records_owned_by_their_team_and_unowned_records(): void
    {
        $team = $this->makeTeam();
        $otherTeam = $this->makeTeam('Jeddah Team', 'فريق جدة');

        $manager = $this->salesManager($team);
        $member = $this->salesRep($team);
        $outsider = $this->salesRep($otherTeam);

        $this->assertTrue($this->resolver->canRead($manager, new OwnedStub(['owner_id' => $member->getKey()])));
        $this->assertTrue($this->resolver->canRead($manager, new OwnedStub(['owner_id' => $manager->getKey()])));
        $this->assertTrue($this->resolver->canRead($manager, new OwnedStub(['owner_id' => null])));
        $this->assertFalse($this->resolver->canRead($manager, new OwnedStub(['owner_id' => $outsider->getKey()])));
        $this->assertTrue($this->resolver->canWrite($manager, new OwnedStub(['owner_id' => $member->getKey()])));
        $this->assertFalse($this->resolver->canWrite($manager, new OwnedStub(['owner_id' => $outsider->getKey()])));
    }

    #[Test]
    public function a_manager_without_a_team_is_a_team_of_one(): void
    {
        $manager = $this->salesManager();
        $rep = $this->salesRep();

        $this->assertTrue($this->resolver->canRead($manager, new OwnedStub(['owner_id' => $manager->getKey()])));
        $this->assertFalse($this->resolver->canRead($manager, new OwnedStub(['owner_id' => $rep->getKey()])));
    }

    #[Test]
    public function assignable_users_shrink_with_the_level(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $member = $this->salesRep($team);
        $outsider = $this->salesRep();
        $admin = $this->admin();

        $forManager = $this->resolver->assignableUsers($manager, 'lead')->pluck('id')->all();
        $this->assertContains($manager->getKey(), $forManager);
        $this->assertContains($member->getKey(), $forManager);
        $this->assertNotContains($outsider->getKey(), $forManager);

        $this->assertSame([$member->getKey()], $this->resolver->assignableUsers($member, 'lead')->pluck('id')->all());
        $this->assertContains($outsider->getKey(), $this->resolver->assignableUsers($admin, 'lead')->pluck('id')->all());
    }
}
