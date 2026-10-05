<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspacePanel;
use App\Filament\Pages\Workspaces;
use App\Models\User;
use App\Support\Filament\PanelUrl;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The workspace screen «لوحة التحكم · اختر مساحة العمل» (decision D-23):
 * always all four workspaces in a fixed order; an enterable one is a link to
 * its home, a locked one has no link at all, carries aria-disabled and says
 * it is not available for the account — in Arabic by default, in English
 * for an English reader.
 */
final class WorkspaceScreenTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
    }

    #[Test]
    public function the_screen_is_the_home_panels_home_and_where_the_landing_page_leads_a_signed_in_visitor(): void
    {
        $this->assertSame(url('/'.WorkspacePanel::HOME), Workspaces::getUrl());
        $this->assertSame(Workspaces::getUrl(), PanelUrl::workspaceScreen());

        // The site root is the landing page (D-24); a signed-in visitor's
        // way on from there is this screen.
        $this->actingAs($this->salesRep())
            ->get('/')
            ->assertOk()
            ->assertSee('href="'.Workspaces::getUrl().'"', escape: false);
    }

    #[Test]
    public function it_shows_all_four_workspaces_in_order_with_the_arabic_title(): void
    {
        $html = $this->screenFor($this->superAdmin())
            ->assertOk()
            ->assertSee('lang="ar"', escape: false)
            ->assertSee('dir="rtl"', escape: false)
            ->assertSee('لوحة التحكم')
            ->assertSee('اختر مساحة العمل')
            ->getContent();

        $positions = [];

        foreach (WorkspacePanel::ordered() as $workspace) {
            $position = strpos((string) $html, 'data-workspace="'.$workspace->id().'"');
            $this->assertNotFalse($position, "the {$workspace->id()} card is missing");
            $positions[] = $position;
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'the cards are not in WorkspacePanel order');
    }

    #[Test]
    public function every_card_of_a_user_who_may_enter_everything_is_a_link_to_its_workspace(): void
    {
        $response = $this->screenFor($this->superAdmin())->assertOk();

        foreach (WorkspacePanel::ordered() as $workspace) {
            $response->assertSee('href="'.PanelUrl::panelHome($workspace).'"', escape: false);
        }

        $response->assertDontSee('aria-disabled="true"', escape: false)
            ->assertDontSee('غير متاح لحسابك');
    }

    #[Test]
    public function an_employee_may_enter_tasks_and_sees_the_other_three_cards_locked(): void
    {
        $html = (string) $this->screenFor($this->employee())->assertOk()->getContent();

        $this->assertStringContainsString('href="'.PanelUrl::panelHome(WorkspacePanel::Tasks).'"', $html);

        foreach ([WorkspacePanel::Sales, WorkspacePanel::Reports, WorkspacePanel::Admin] as $locked) {
            $this->assertStringNotContainsString('href="'.PanelUrl::panelHome($locked).'"', $this->cardsOf($html), "the locked {$locked->id()} card links somewhere");
            $this->assertMatchesRegularExpression(
                '/<div[^>]*class="crm-workspace-card crm-workspace-card-locked"[^>]*data-workspace="'.$locked->id().'"[^>]*aria-disabled="true"/',
                $html,
            );
        }

        $this->assertSame(3, substr_count($html, 'aria-disabled="true"'));
        $this->assertSame(3, substr_count($html, 'غير متاح لحسابك'));
    }

    #[Test]
    public function a_user_without_any_workspace_sees_four_locked_cards(): void
    {
        $html = (string) $this->screenFor($this->userWithPermissions(null, []))->assertOk()->getContent();

        $this->assertSame(4, substr_count($html, 'aria-disabled="true"'));
        $this->assertStringNotContainsString('class="crm-workspace-card"', $html);
    }

    #[Test]
    public function an_english_reader_gets_the_english_screen_and_locked_state(): void
    {
        $reader = $this->employee();
        $reader->forceFill(['locale' => 'en'])->save();

        $this->screenFor($reader)
            ->assertOk()
            ->assertSee('lang="en"', escape: false)
            ->assertSee('dir="ltr"', escape: false)
            ->assertSee('Control panel')
            ->assertSee('Choose a workspace')
            ->assertSee('Not available for your account')
            ->assertSee(WorkspacePanel::Tasks->getDescription())
            ->assertDontSee('غير متاح لحسابك');
    }

    #[Test]
    public function the_brand_of_every_panel_leads_to_the_screen(): void
    {
        $admin = $this->superAdmin();

        foreach (WorkspacePanel::ordered() as $workspace) {
            $this->assertSame(PanelUrl::workspaceScreen(), Filament::getPanel($workspace->id())->getHomeUrl());

            $this->actingAs($admin)->get(PanelUrl::panelHome($workspace))
                ->assertOk()
                ->assertSee('href="'.PanelUrl::workspaceScreen().'"', escape: false);
        }
    }

    #[Test]
    public function the_home_panel_and_every_workspace_are_kept_out_of_search_engines(): void
    {
        // The guard is the noindex header (D-24 review notes): robots.txt lets
        // crawlers in so they can read it.
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(PanelUrl::workspaceScreen())->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        foreach (WorkspacePanel::ordered() as $workspace) {
            $this->actingAs($admin)->get(PanelUrl::panelHome($workspace))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $rules = array_map('trim', file(public_path('robots.txt')) ?: []);

        foreach (['/'.WorkspacePanel::HOME, ...array_map(static fn (WorkspacePanel $workspace): string => '/'.$workspace->path(), WorkspacePanel::ordered())] as $path) {
            $this->assertNotContains('Disallow: '.$path, $rules, "crawlers could never read the noindex of {$path}");
        }
    }

    /**
     * @return TestResponse<Response>
     */
    private function screenFor(User $user): TestResponse
    {
        return $this->actingAs($user)->get(Workspaces::getUrl());
    }

    /** The markup of the card grid only, without the top bar's switcher. */
    private function cardsOf(string $html): string
    {
        $start = strpos($html, '<ul class="crm-workspaces"');
        $this->assertNotFalse($start, 'the card grid is missing');

        return substr($html, $start, (int) strpos($html, '</ul>', $start) - $start);
    }
}
