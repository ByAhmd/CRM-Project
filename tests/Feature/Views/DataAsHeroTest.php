<?php

declare(strict_types=1);

namespace Tests\Feature\Views;

use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Support\InitialsAvatar;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * «Data as hero» (decision A-23): the initials avatar beside the primary
 * name on the Leads, Contacts and Accounts lists, the lead score as a real
 * 0-100 meter, and the amount emphasis on deals — server-rendered through
 * the escape-safe Htmlable affix path, styled only by theme.css tokens.
 * Losing any ingredient would leave A-23 recorded but undelivered.
 */
final class DataAsHeroTest extends TestCase
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
    public function the_lists_open_with_an_initials_avatar_beside_the_primary_name(): void
    {
        $admin = $this->admin();
        Lead::factory()->create(['owner_id' => $admin->getKey()]);
        Account::factory()->create(['owner_id' => $admin->getKey()]);
        Contact::factory()->create(['owner_id' => $admin->getKey()]);

        foreach ([ListLeads::class, ListContacts::class, ListAccounts::class] as $page) {
            Livewire::actingAs($admin)
                ->test($page)
                ->assertSeeHtml('crm-avatar')
                ->assertSeeHtml('--crm-avatar-h:');
        }
    }

    #[Test]
    public function the_avatar_is_deterministic_and_escapes_the_initials(): void
    {
        $first = InitialsAvatar::column(TextColumn::make('name'), 'name');

        // Same name, same markup — the hue is derived from the name, so a
        // record keeps its colour on every page and device.
        $account = Account::factory()->make(['name' => 'شركة الأفق الجديد']);
        $prefix = $first->record($account)->getPrefix();
        $again = $first->record($account)->getPrefix();

        $this->assertNotNull($prefix);
        $html = (string) $prefix;
        $this->assertSame($html, (string) $again);
        $this->assertStringContainsString('crm-avatar', $html);
        $this->assertStringContainsString('شا', $html, 'initials are the first letters of the first and last words');

        // A hostile name cannot break out of the span: the initials are escaped.
        $hostile = Account::factory()->make(['name' => '<svg onload=x> Corp']);
        $this->assertStringContainsString('&lt;C', (string) $first->record($hostile)->getPrefix());
    }

    #[Test]
    public function the_lead_score_renders_as_a_meter_on_the_list_and_the_view_page(): void
    {
        $admin = $this->admin();
        // The override wins in effective_score, and no rescoring pass moves it.
        $lead = Lead::factory()->create(['owner_id' => $admin->getKey(), 'score_override' => 55]);

        Livewire::actingAs($admin)
            ->test(ListLeads::class)
            ->assertSeeHtml('crm-score-meter')
            ->assertSeeHtml('inline-size: 55%');

        Livewire::actingAs($admin)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->assertSeeHtml('crm-score-meter')
            ->assertSeeHtml('inline-size: 55%');
    }

    #[Test]
    public function deal_amounts_wear_the_amount_emphasis(): void
    {
        $admin = $this->admin();
        Deal::factory()->create(['owner_id' => $admin->getKey()]);

        Livewire::actingAs($admin)
            ->test(ListDeals::class)
            ->assertSeeHtml('crm-amount');
    }

    #[Test]
    public function the_theme_carries_the_data_as_hero_tokens(): void
    {
        $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

        foreach (['--crm-avatar-s', '--crm-avatar-bg-l', '--crm-avatar-fg-l', '.crm-avatar', '.crm-score-meter', '.crm-score-meter-fill', '.crm-amount'] as $needle) {
            $this->assertStringContainsString($needle, $css, "the theme lost [{$needle}] - A-23 styling must live in theme.css under the token discipline");
        }
    }
}
