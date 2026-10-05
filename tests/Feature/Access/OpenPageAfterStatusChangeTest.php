<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\UserStatus;
use App\Filament\Resources\Leads\LeadResource;
use App\Http\Middleware\AuthenticateWorkspace;
use App\Models\User;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * A user who stops being able to sign in while a panel page is still open in
 * their browser cannot keep acting through that page (D-11, A-12).
 *
 * Filament registers its Authenticate middleware as Livewire persistent
 * middleware for every panel, so User::canAccessPanel() runs again on each
 * update request an open page sends, not only when the page is loaded.
 * Livewire's component test harness bypasses HTTP middleware, so these tests
 * replay the real request: the snapshot rendered into the page, posted to the
 * update endpoint with Livewire's headers.
 */
final class OpenPageAfterStatusChangeTest extends TestCase
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
    public function the_panel_authentication_middleware_runs_on_every_livewire_request(): void
    {
        $this->actingAs($this->salesRep())->get(LeadResource::getUrl('index'))->assertOk();

        $this->assertContains(Authenticate::class, Livewire::getPersistentMiddleware());
        // Livewire matches by exact class name: the gate of the workspace
        // panels (D-23) must be registered itself, its parent does not cover it.
        $this->assertContains(AuthenticateWorkspace::class, Livewire::getPersistentMiddleware());
    }

    #[Test]
    public function a_user_disabled_while_a_page_is_open_is_refused_on_the_next_request_from_it(): void
    {
        $rep = $this->salesRep();
        $snapshot = $this->openLeadList($rep);

        $this->livewireUpdate($snapshot)->assertOk();

        $rep->forceFill(['status' => UserStatus::Disabled])->save();

        $this->livewireUpdate($snapshot)->assertForbidden();
    }

    #[Test]
    public function a_user_set_back_to_pending_while_a_page_is_open_is_refused_on_the_next_request_from_it(): void
    {
        $rep = $this->salesRep();
        $snapshot = $this->openLeadList($rep);

        $this->livewireUpdate($snapshot)->assertOk();

        $rep->forceFill(['status' => UserStatus::Pending])->save();

        $this->livewireUpdate($snapshot)->assertForbidden();
    }

    private function openLeadList(User $user): string
    {
        return $this->firstLivewireSnapshot($user, LeadResource::getUrl('index'));
    }
}
