<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Support\OwnedStub;
use Tests\Support\OwnedStubPolicy;
use Tests\Support\OwnedStubScoper;
use Tests\TestCase;

/**
 * The shared policy trait and the resource query scope agree with the resolver
 * (D-4) and close Filament's allow-on-absent hole for bulk abilities.
 */
final class ChecksPermissionsPolicyTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private OwnedStubPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->policy = new OwnedStubPolicy;

        Schema::dropIfExists('owned_stubs');
        Schema::create('owned_stubs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('owned_stubs');

        parent::tearDown();
    }

    #[Test]
    public function abilities_combine_the_permission_and_the_record_scope(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $mine = new OwnedStub(['owner_id' => $rep->getKey()]);
        $theirs = new OwnedStub(['owner_id' => $other->getKey()]);

        $this->assertTrue($this->policy->viewAny($rep));
        $this->assertTrue($this->policy->view($rep, $mine));
        $this->assertFalse($this->policy->view($rep, $theirs));
        $this->assertTrue($this->policy->update($rep, $mine));
        $this->assertFalse($this->policy->update($rep, $theirs));
        $this->assertFalse($this->policy->delete($rep, $mine), 'reps hold no lead.delete');
        $this->assertFalse($this->policy->forceDelete($rep, $mine));
    }

    #[Test]
    public function bulk_abilities_defer_to_their_singular_form_and_force_delete_is_never_granted(): void
    {
        $manager = $this->salesManager();
        $rep = $this->salesRep();
        $admin = $this->admin();

        $this->assertTrue($this->policy->deleteAny($manager));
        $this->assertFalse($this->policy->deleteAny($rep));
        $this->assertTrue($this->policy->restoreAny($manager));
        $this->assertFalse($this->policy->forceDeleteAny($admin));
        $this->assertFalse($this->policy->forceDelete($admin));
    }

    #[Test]
    public function the_resource_scope_returns_exactly_the_rows_the_resolver_allows(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $member = $this->salesRep($team);
        $outsider = $this->salesRep();
        $admin = $this->admin();

        $mine = OwnedStub::query()->create(['owner_id' => $member->getKey()]);
        $managers = OwnedStub::query()->create(['owner_id' => $manager->getKey()]);
        $outsiders = OwnedStub::query()->create(['owner_id' => $outsider->getKey()]);
        $unowned = OwnedStub::query()->create(['owner_id' => null]);

        $this->actingAs($member);
        $this->assertSame([$mine->getKey()], OwnedStubScoper::visibleStubs()->pluck('id')->all());

        $this->actingAs($manager);
        $this->assertEqualsCanonicalizing([$mine->getKey(), $managers->getKey(), $unowned->getKey()], OwnedStubScoper::visibleStubs()->pluck('id')->all());

        $this->actingAs($admin);
        $this->assertSame(4, OwnedStubScoper::visibleStubs()->count());

        $outsider->syncRoles([]);
        $this->actingAs($outsider->refresh());
        $this->assertSame(0, OwnedStubScoper::visibleStubs()->count(), 'no view permission fails closed');

        auth()->logout();
        $this->assertSame(0, OwnedStubScoper::visibleStubs()->count(), 'guests see nothing');
    }
}
