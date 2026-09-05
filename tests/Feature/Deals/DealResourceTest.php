<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Enums\AccountType;
use App\Enums\ActivityLogEvent;
use App\Enums\CloseReasonKind;
use App\Enums\DealStatus;
use App\Enums\StageKind;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\DealsRelationManager;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\Schemas\DealInfolist;
use App\Models\Account;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\DealStageLog;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Services\Deals\DealCloseService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The deal resource (decisions D-4, D-8): the form with its line items, the
 * frozen stage, the list tabs, visibility, the workflow actions and the
 * account page's deals manager.
 */
final class DealResourceTest extends TestCase
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
    public function a_rep_creates_a_deal_with_line_items_and_the_amount_is_computed_from_them(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $laptop = Product::factory()->create(['unit_price' => 100]);
        $support = Product::factory()->create(['unit_price' => 50]);

        Livewire::actingAs($rep)
            ->test(CreateDeal::class)
            ->fillForm([
                'title' => 'صفقة الأجهزة',
                'account_id' => $account->getKey(),
                'amount' => 5,
                'products' => [
                    ['product_id' => $laptop->getKey(), 'description' => 'Laptops', 'quantity' => 2, 'unit_price' => 100, 'discount_percent' => 10],
                    ['product_id' => $support->getKey(), 'description' => 'Support', 'quantity' => 1, 'unit_price' => 50, 'discount_percent' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $deal = Deal::query()->where('title', 'صفقة الأجهزة')->firstOrFail();

        $this->assertSame('230.00', $deal->amount);
        $this->assertSame(app(SettingsRepository::class)->currency(), $deal->currency);
        $this->assertSame(DealStatus::Open, $deal->status);
        $this->assertTrue($deal->stage->isDefault());
        $this->assertTrue($deal->pipeline->isDefault());
        $this->assertSame($rep->getKey(), $deal->owner_id);
        $this->assertSame($rep->getKey(), $deal->created_by);
        $this->assertSame($account->getKey(), $deal->account_id);
        $this->assertSame(2, $deal->products()->count());
        $this->assertSame(['180.00', '50.00'], $deal->products->pluck('line_total')->all());
        $this->assertSame([1, 2], $deal->products->pluck('sort')->all());
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::DealCreated->value, 'subject_id' => $deal->getKey()]);
    }

    #[Test]
    public function a_deal_without_lines_keeps_the_amount_entered_by_hand(): void
    {
        $rep = $this->salesRep();

        Livewire::actingAs($rep)
            ->test(CreateDeal::class)
            ->fillForm(['title' => 'Manual amount', 'amount' => 1234.5])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('1234.50', Deal::query()->where('title', 'Manual amount')->firstOrFail()->amount);
    }

    #[Test]
    public function the_form_validates_the_title_the_probability_and_the_line_items(): void
    {
        $product = Product::factory()->create();

        $page = Livewire::actingAs($this->salesRep())
            ->test(CreateDeal::class)
            ->fillForm([
                'title' => '',
                'probability' => 150,
                'products' => [
                    ['product_id' => $product->getKey(), 'quantity' => 0, 'unit_price' => 10, 'discount_percent' => 120],
                ],
            ]);

        $lines = $page->get('data.products');
        $this->assertIsArray($lines);
        $uuid = (string) array_key_first($lines);

        $page->call('create')
            ->assertHasFormErrors([
                'title' => 'required',
                'probability' => 'max',
                "products.{$uuid}.quantity" => 'min',
                "products.{$uuid}.discount_percent" => 'max',
            ]);

        $this->assertSame(0, Deal::query()->count());
    }

    #[Test]
    public function the_edit_form_hides_the_pipeline_and_the_stage_and_cannot_change_them(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $original = $deal->stage_id;
        $negotiation = $this->openStage($deal, 2);

        Livewire::actingAs($rep)
            ->test(EditDeal::class, ['record' => $deal->getRouteKey()])
            ->assertFormFieldHidden('pipeline_id')
            ->assertFormFieldHidden('stage_id')
            ->assertSee(__('deals.helpers.stage_readonly'))
            ->assertSee(__('deals.sections.line_items'))
            ->fillForm(['title' => 'Renamed', 'stage_id' => $negotiation->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $deal->refresh();

        $this->assertSame('Renamed', $deal->title);
        $this->assertSame($original, $deal->stage_id);
        $this->assertSame(0, DealStageLog::query()->where('deal_id', $deal->getKey())->count());
    }

    #[Test]
    public function the_amount_is_locked_on_the_edit_form_once_the_deal_has_lines(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $product = Product::factory()->create(['unit_price' => 300]);
        $deal->products()->create(['product_id' => $product->getKey(), 'quantity' => 2, 'unit_price' => 300, 'discount_percent' => 0]);

        Livewire::actingAs($rep)
            ->test(EditDeal::class, ['record' => $deal->getRouteKey()])
            ->assertFormFieldDisabled('amount')
            ->fillForm(['amount' => 1])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('600.00', $deal->refresh()->amount);
    }

    #[Test]
    public function removing_every_line_re_enables_the_manual_amount_in_the_same_save(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $product = Product::factory()->create(['unit_price' => 300]);
        $deal->products()->create(['product_id' => $product->getKey(), 'quantity' => 2, 'unit_price' => 300, 'discount_percent' => 0]);

        Livewire::actingAs($rep)
            ->test(EditDeal::class, ['record' => $deal->getRouteKey()])
            ->assertFormFieldDisabled('amount')
            ->fillForm(['products' => []])
            ->assertFormFieldEnabled('amount')
            ->fillForm(['amount' => 999.5])
            ->call('save')
            ->assertHasNoFormErrors();

        $deal->refresh();

        $this->assertSame(0, $deal->products()->count());
        $this->assertSame('999.50', $deal->amount);
    }

    #[Test]
    public function a_won_deal_cannot_be_edited(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);

        $this->actingAs($rep)->get(DealResource::getUrl('edit', ['record' => $deal]))->assertOk();

        app(DealCloseService::class)->win($deal, $this->reasonOfKind(CloseReasonKind::Won), $rep);

        $this->actingAs($rep)->get(DealResource::getUrl('edit', ['record' => $deal]))->assertForbidden();
        $this->actingAs($rep)->get(DealResource::getUrl('view', ['record' => $deal]))->assertOk();
    }

    #[Test]
    public function the_list_tabs_split_deals_by_status(): void
    {
        $admin = $this->admin();
        $open = Deal::factory()->create(['owner_id' => $admin->getKey()]);
        $won = Deal::factory()->create(['owner_id' => $admin->getKey()]);
        $lost = Deal::factory()->create(['owner_id' => $admin->getKey()]);
        app(DealCloseService::class)->win($won, $this->reasonOfKind(CloseReasonKind::Won), $admin);
        app(DealCloseService::class)->lose($lost, $this->reasonOfKind(CloseReasonKind::Lost), $admin);

        Livewire::actingAs($admin)
            ->test(ListDeals::class)
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$won, $lost])
            ->set('activeTab', 'won')
            ->assertCanSeeTableRecords([$won])
            ->assertCanNotSeeTableRecords([$open, $lost])
            ->set('activeTab', 'lost')
            ->assertCanSeeTableRecords([$lost])
            ->assertCanNotSeeTableRecords([$open, $won])
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$open, $won, $lost]);
    }

    #[Test]
    public function visibility_follows_ownership_and_teams(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $other = $this->salesRep();
        $mine = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $theirs = Deal::factory()->create(['owner_id' => $other->getKey()]);

        Livewire::actingAs($rep)
            ->test(ListDeals::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        Livewire::actingAs($manager)
            ->test(ListDeals::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        Livewire::actingAs($this->admin())
            ->test(ListDeals::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$mine, $theirs]);

        $this->actingAs($rep)->get(DealResource::getUrl('view', ['record' => $mine]))->assertOk();
        $this->actingAs($rep)->get(DealResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
        $this->actingAs($this->readOnly())->get(DealResource::getUrl('view', ['record' => $theirs]))->assertOk();
        $this->actingAs($this->readOnly())->get(DealResource::getUrl('create'))->assertForbidden();
    }

    #[Test]
    public function the_change_stage_action_moves_the_deal_and_notifies(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $proposal = $this->openStage($deal, 1);

        Livewire::actingAs($rep)
            ->test(ViewDeal::class, ['record' => $deal->getRouteKey()])
            ->assertActionVisible('changeStage')
            ->callAction('changeStage', data: ['stage_id' => $proposal->getKey(), 'note' => 'Proposal sent'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('deals.notifications.stage_changed', ['stage' => $proposal->display_name]));

        $deal->refresh();
        $this->assertSame($proposal->getKey(), $deal->stage_id);
        $this->assertSame('Proposal sent', DealStageLog::query()->where('deal_id', $deal->getKey())->firstOrFail()->notes);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::DealStageChanged->value, 'subject_id' => $deal->getKey()]);
    }

    #[Test]
    public function the_change_stage_action_is_hidden_from_a_read_only_user(): void
    {
        $deal = Deal::factory()->create(['owner_id' => $this->salesRep()->getKey()]);

        Livewire::actingAs($this->readOnly())
            ->test(ViewDeal::class, ['record' => $deal->getRouteKey()])
            ->assertActionHidden('changeStage')
            ->assertActionHidden('markWon')
            ->assertActionHidden('markLost');

        Livewire::actingAs($this->readOnly())
            ->test(ListDeals::class)
            ->assertTableActionHidden('changeStage', $deal)
            ->assertTableActionHidden('markWon', $deal);
    }

    #[Test]
    public function marking_a_deal_won_requires_a_reason_and_promotes_the_prospect_account(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey(), 'type' => AccountType::Prospect]);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);
        $reason = $this->reasonOfKind(CloseReasonKind::Won);

        Livewire::actingAs($rep)
            ->test(ViewDeal::class, ['record' => $deal->getRouteKey()])
            ->callAction('markWon', data: ['close_reason_id' => null])
            ->assertHasActionErrors(['close_reason_id' => 'required']);

        $this->assertSame(DealStatus::Open, $deal->refresh()->status);

        Livewire::actingAs($rep)
            ->test(ViewDeal::class, ['record' => $deal->getRouteKey()])
            ->callAction('markWon', data: ['close_reason_id' => $reason->getKey(), 'note' => 'Signed'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('deals.notifications.won'));

        $deal->refresh();
        $this->assertSame(DealStatus::Won, $deal->status);
        $this->assertSame($reason->getKey(), $deal->close_reason_id);
        $this->assertNotNull($deal->won_at);
        $this->assertSame(AccountType::Customer, $account->refresh()->type);
    }

    #[Test]
    public function marking_a_deal_lost_stores_the_reason_and_the_notes(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $reason = $this->reasonOfKind(CloseReasonKind::Lost);

        Livewire::actingAs($rep)
            ->test(ListDeals::class)
            ->callTableAction('markLost', $deal, data: ['close_reason_id' => $reason->getKey(), 'lost_notes' => 'Chose a competitor'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('deals.notifications.lost'));

        $deal->refresh();
        $this->assertSame(DealStatus::Lost, $deal->status);
        $this->assertSame($reason->getKey(), $deal->close_reason_id);
        $this->assertSame('Chose a competitor', $deal->lost_notes);
        $this->assertNotNull($deal->lost_at);
    }

    #[Test]
    public function a_won_reason_is_refused_when_marking_a_deal_lost(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(ViewDeal::class, ['record' => $deal->getRouteKey()])
            ->callAction('markLost', data: ['close_reason_id' => $this->reasonOfKind(CloseReasonKind::Won)->getKey()])
            ->assertHasActionErrors(['close_reason_id']);

        $this->assertSame(DealStatus::Open, $deal->refresh()->status);
    }

    #[Test]
    public function reopen_is_offered_only_on_a_closed_deal_and_refused_for_a_read_only_user(): void
    {
        $rep = $this->salesRep();
        $readOnly = $this->readOnly();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(ViewDeal::class, ['record' => $deal->getRouteKey()])
            ->assertActionHidden('reopen');

        app(DealCloseService::class)->lose($deal, $this->reasonOfKind(CloseReasonKind::Lost), $rep);

        Livewire::actingAs($readOnly)
            ->test(ViewDeal::class, ['record' => $deal->getRouteKey()])
            ->assertActionHidden('reopen');

        $this->assertFalse($readOnly->can('reopen', $deal));

        Livewire::actingAs($rep)
            ->test(ViewDeal::class, ['record' => $deal->getRouteKey()])
            ->assertActionHidden('changeStage')
            ->assertActionHidden('markWon')
            ->assertActionVisible('reopen')
            ->callAction('reopen', data: ['note' => 'Back on the table'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('deals.notifications.reopened'));

        $deal->refresh();
        $this->assertSame(DealStatus::Open, $deal->status);
        $this->assertTrue($deal->stage->isDefault());
        $this->assertNull($deal->close_reason_id);
    }

    #[Test]
    public function a_manager_reassigns_a_deal_but_a_rep_does_not_see_the_action(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(ListDeals::class)
            ->assertTableActionHidden('assign', $deal);

        Livewire::actingAs($manager)
            ->test(ViewDeal::class, ['record' => $deal->getRouteKey()])
            ->assertActionVisible('assign')
            ->callAction('assign', data: ['owner_id' => $manager->getKey()])
            ->assertNotified();

        $this->assertSame($manager->getKey(), $deal->refresh()->owner_id);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::DealAssigned->value, 'subject_id' => $deal->getKey()]);
    }

    #[Test]
    public function the_view_page_renders_the_summary_and_the_stage_history(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $product = Product::factory()->create(['unit_price' => 80]);
        $deal->products()->create(['product_id' => $product->getKey(), 'quantity' => 1, 'unit_price' => 80, 'discount_percent' => 0]);

        Livewire::actingAs($rep)
            ->test(ViewDeal::class, ['record' => $deal->getRouteKey()])
            ->assertOk()
            ->assertSee($deal->title)
            ->assertSee($product->display_name)
            ->assertSee(__('deals.sections.line_items'))
            ->assertSee(__('deals.sections.stage_history'))
            ->assertDontSee(__('deals.fields.closed_by'));
    }

    #[Test]
    public function the_stage_duration_is_pluralised_in_both_locales(): void
    {
        app()->setLocale('en');
        $this->assertSame('1 day, 1 hour', DealInfolist::formatDuration(90000));
        $this->assertSame('2 days, 0 hours', DealInfolist::formatDuration(172800));
        $this->assertSame('3 hours, 5 minutes', DealInfolist::formatDuration(11100));
        $this->assertSame('1 minute', DealInfolist::formatDuration(60));

        app()->setLocale('ar');
        $this->assertSame('يوم واحد, ساعة واحدة', DealInfolist::formatDuration(90000));
        $this->assertSame('يومان, 0 ساعة', DealInfolist::formatDuration(172800));
        $this->assertSame('3 ساعات, 5 دقائق', DealInfolist::formatDuration(11100));
        $this->assertSame('12 يوماً, 0 ساعة', DealInfolist::formatDuration(12 * 86400));
    }

    #[Test]
    public function global_search_matches_the_account_name_and_stays_in_scope(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $account = Account::factory()->create(['name' => 'Zeta Holdings', 'owner_id' => $rep->getKey()]);
        $mine = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);
        Deal::factory()->create(['owner_id' => $other->getKey(), 'account_id' => $account->getKey()]);

        $this->actingAs($rep);

        $this->assertContains('account.name', DealResource::getGloballySearchableAttributes());
        $this->assertSame([$mine->getKey()], DealResource::getGlobalSearchEloquentQuery()->pluck('id')->all());
        $this->assertArrayHasKey(__('deals.fields.stage'), DealResource::getGlobalSearchResultDetails($mine));
    }

    #[Test]
    public function the_deals_manager_on_the_account_page_lists_the_account_deals_within_scope_and_creates_one(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $mine = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey()]);
        $theirs = Deal::factory()->create(['owner_id' => $other->getKey(), 'account_id' => $account->getKey()]);
        $elsewhere = Deal::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(DealsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs, $elsewhere])
            ->callTableAction('create', data: ['title' => 'From the account page', 'amount' => 900])
            ->assertHasNoTableActionErrors();

        $created = Deal::query()->where('title', 'From the account page')->firstOrFail();

        $this->assertSame($account->getKey(), $created->account_id);
        $this->assertSame($rep->getKey(), $created->owner_id);
        $this->assertSame($rep->getKey(), $created->created_by);
        $this->assertSame(DealStatus::Open, $created->status);
        $this->assertTrue($created->stage->isDefault());
        $this->assertSame('900.00', $created->amount);
    }

    #[Test]
    public function a_soft_deleted_deal_can_be_restored_by_an_admin(): void
    {
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => $admin->getKey()]);

        Livewire::actingAs($admin)
            ->test(EditDeal::class, ['record' => $deal->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted('deals', ['id' => $deal->getKey()]);

        Livewire::actingAs($admin)
            ->test(EditDeal::class, ['record' => $deal->getRouteKey()])
            ->callAction('restore');

        $this->assertNull($deal->refresh()->deleted_at);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::DealRestored->value, 'subject_id' => $deal->getKey()]);
    }

    /** The n-th Open stage of the deal's pipeline, in pipeline order (0 = the default stage). */
    private function openStage(Deal $deal, int $position): PipelineStage
    {
        return PipelineStage::query()
            ->where('pipeline_id', $deal->pipeline_id)
            ->where('kind', StageKind::Open->value)
            ->orderBy('sort')
            ->orderBy('id')
            ->skip($position)
            ->firstOrFail();
    }

    private function reasonOfKind(CloseReasonKind $kind): DealCloseReason
    {
        return DealCloseReason::query()->where('kind', $kind->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
    }
}
