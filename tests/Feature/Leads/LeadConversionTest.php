<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Enums\AccountType;
use App\Enums\ActivityLogEvent;
use App\Enums\DealStatus;
use App\Enums\ForecastCategory;
use App\Enums\LeadStatusKind;
use App\Exceptions\Leads\InvalidLeadTransitionException;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealStageLog;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\User;
use App\Services\Leads\ConversionRequest;
use App\Services\Leads\LeadConversionWorkflow;
use App\Services\Leads\LeadStatusWorkflow;
use App\Services\Settings\SettingsRepository;
use Filament\Forms\Components\Radio;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Lead conversion (decisions D-6, D-7): a qualified lead becomes an account,
 * a contact and optionally a deal in one transaction, then freezes.
 */
final class LeadConversionTest extends TestCase
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
    public function a_qualified_lead_converts_into_a_new_account_a_new_contact_and_a_deal(): void
    {
        $rep = $this->salesRep();
        $source = LeadSource::query()->firstOrFail();
        $tag = Tag::factory()->create();
        $lead = $this->qualifiedLead($rep, [
            'first_name' => 'سارة',
            'last_name' => 'العتيبي',
            'company_name' => 'شركة الأفق',
            'job_title' => 'مديرة المشتريات',
            'email' => 'Sara@Ofoq.example',
            'phone' => '0501234567',
            'website' => 'https://ofoq.example',
            'address_line' => 'طريق الملك فهد',
            'city' => 'الرياض',
            'region' => 'الرياض',
            'country' => 'SA',
            'postal_code' => '12345',
            'lead_source_id' => $source->getKey(),
        ]);
        $lead->tags()->sync([$tag->getKey()]);

        $result = app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest(
            accountMode: ConversionRequest::ACCOUNT_NEW,
            createDeal: true,
            dealAmount: '15000.50',
            expectedCloseDate: '2026-12-31',
            note: 'Signed the NDA.',
        ), $rep);

        $account = $result->account;
        $this->assertInstanceOf(Account::class, $account);
        $this->assertSame('شركة الأفق', $account->name);
        $this->assertSame(AccountType::Prospect, $account->type);
        $this->assertSame('Sara@Ofoq.example', $account->email);
        $this->assertSame('0501234567', $account->phone);
        $this->assertSame('https://ofoq.example', $account->website);
        $this->assertSame('طريق الملك فهد', $account->address_line);
        $this->assertSame('الرياض', $account->city);
        $this->assertSame('12345', $account->postal_code);
        $this->assertNull($account->industry_id);
        $this->assertSame($rep->getKey(), $account->owner_id);
        $this->assertSame($rep->getKey(), $account->created_by);
        $this->assertSame([$tag->getKey()], $account->tags()->pluck('tags.id')->all());

        $contact = $result->contact;
        $this->assertSame('سارة العتيبي', $contact->full_name);
        $this->assertSame('مديرة المشتريات', $contact->job_title);
        $this->assertSame('sara@ofoq.example', $contact->email_normalized);
        $this->assertSame('0501234567', $contact->mobile);
        $this->assertSame($account->getKey(), $contact->account_id);
        $this->assertSame($lead->getKey(), $contact->lead_id);
        $this->assertTrue($contact->is_primary);
        $this->assertSame($rep->getKey(), $contact->owner_id);
        $this->assertSame($rep->getKey(), $contact->created_by);
        $this->assertSame([$tag->getKey()], $contact->tags()->pluck('tags.id')->all());

        $deal = $result->deal;
        $this->assertInstanceOf(Deal::class, $deal);
        $pipeline = Pipeline::query()->where('is_default', true)->firstOrFail();
        $this->assertSame('شركة الأفق', $deal->title);
        $this->assertSame($pipeline->getKey(), $deal->pipeline_id);
        $this->assertSame($pipeline->defaultStage()->firstOrFail()->getKey(), $deal->stage_id);
        $this->assertSame(DealStatus::Open, $deal->status);
        $this->assertSame(ForecastCategory::Pipeline, $deal->forecast_category);
        $this->assertSame($account->getKey(), $deal->account_id);
        $this->assertSame($contact->getKey(), $deal->contact_id);
        $this->assertSame($lead->getKey(), $deal->lead_id);
        $this->assertSame($source->getKey(), $deal->lead_source_id);
        $this->assertSame('15000.50', $deal->amount);
        $this->assertSame(app(SettingsRepository::class)->currency(), $deal->currency);
        $this->assertSame('2026-12-31', $deal->expected_close_date?->toDateString());
        $this->assertSame($rep->getKey(), $deal->owner_id);
        $this->assertSame($rep->getKey(), $deal->created_by);
        $this->assertSame(0, DealStageLog::query()->where('deal_id', $deal->getKey())->count());

        $lead->refresh();
        $this->assertTrue($lead->isConverted());
        $this->assertSame(LeadStatusKind::Converted, $lead->status->kind);
        $this->assertSame($rep->getKey(), $lead->converted_by);
        $this->assertSame($account->getKey(), $lead->converted_account_id);
        $this->assertSame($contact->getKey(), $lead->converted_contact_id);
        $this->assertSame($deal->getKey(), $lead->converted_deal_id);

        $log = LeadStatusLog::query()->where('lead_id', $lead->getKey())->orderByDesc('id')->firstOrFail();
        $this->assertSame($this->statusOfKind(LeadStatusKind::Qualified)->getKey(), (int) $log->from_status_id);
        $this->assertSame($this->statusOfKind(LeadStatusKind::Converted)->getKey(), (int) $log->to_status_id);
        $this->assertSame($rep->getKey(), (int) $log->changed_by);
        $this->assertSame('Signed the NDA.', $log->notes);

        $audit = ActivityLog::query()->where('description', ActivityLogEvent::LeadConverted->value)->latest('id')->firstOrFail();
        $this->assertSame($lead->getKey(), (int) $audit->subject_id);
        $this->assertSame($rep->getKey(), (int) $audit->causer_id);
        $this->assertSame('سارة العتيبي', $audit->properties->get('subject_label'));
        $this->assertSame('شركة الأفق', $audit->properties->get('account_name'));
        $this->assertSame('سارة العتيبي', $audit->properties->get('contact_name'));
        $this->assertSame('شركة الأفق', $audit->properties->get('deal_title'));
        $this->assertSame($deal->getKey(), (int) $audit->properties->get('deal_id'));
        $this->assertSame('Signed the NDA.', $audit->properties->get('note'));
    }

    #[Test]
    public function a_person_lead_converts_into_a_contact_without_an_account_or_a_deal(): void
    {
        $rep = $this->salesRep();
        $lead = $this->qualifiedLead($rep, ['company_name' => null, 'city' => 'جدة']);

        $result = app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest(
            accountMode: ConversionRequest::ACCOUNT_NONE,
            createDeal: false,
        ), $rep);

        $this->assertNull($result->account);
        $this->assertNull($result->deal);
        $this->assertNull($result->contact->account_id);
        $this->assertFalse($result->contact->is_primary);
        $this->assertSame('جدة', $result->contact->city);
        $this->assertSame($lead->getKey(), $result->contact->lead_id);
        $this->assertSame(0, Account::query()->count());
        $this->assertSame(0, Deal::query()->count());

        $lead->refresh();
        $this->assertTrue($lead->isConverted());
        $this->assertNull($lead->converted_account_id);
        $this->assertNull($lead->converted_deal_id);
        $this->assertSame($result->contact->getKey(), $lead->converted_contact_id);
    }

    #[Test]
    public function an_existing_account_and_contact_can_be_linked(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => null]);
        $lead = $this->qualifiedLead($rep);

        $result = app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest(
            accountMode: ConversionRequest::ACCOUNT_EXISTING,
            accountId: $account->getKey(),
            contactMode: ConversionRequest::CONTACT_EXISTING,
            contactId: $contact->getKey(),
            createDeal: true,
            dealTitle: 'Renewal 2027',
        ), $rep);

        $this->assertSame($account->getKey(), $result->account?->getKey());
        $this->assertSame($contact->getKey(), $result->contact->getKey());
        $this->assertSame(1, Account::query()->count());
        $this->assertSame(1, Contact::query()->count());

        $contact->refresh();
        $this->assertSame($lead->getKey(), $contact->lead_id);
        $this->assertSame($account->getKey(), $contact->account_id);
        // The account had no primary contact, so the linked contact becomes it (D-6).
        $this->assertTrue($contact->is_primary);
        $this->assertSame(1, Contact::query()->where('account_id', $account->getKey())->where('is_primary', true)->count());

        $deal = $result->deal;
        $this->assertInstanceOf(Deal::class, $deal);
        $this->assertSame('Renewal 2027', $deal->title);
        $this->assertSame($account->getKey(), $deal->account_id);
        $this->assertSame($contact->getKey(), $deal->contact_id);
        $this->assertSame($account->getKey(), $lead->refresh()->converted_account_id);
    }

    #[Test]
    public function a_default_title_and_a_zero_amount_are_used_when_the_deal_fields_are_empty(): void
    {
        $rep = $this->salesRep();
        $lead = $this->qualifiedLead($rep, ['first_name' => 'Nora', 'last_name' => 'Fahad', 'company_name' => null]);

        $result = app(LeadConversionWorkflow::class)->convert($lead, ConversionRequest::fromArray([
            'account_mode' => 'none',
            'contact_mode' => 'new',
            'create_deal' => true,
            'deal_title' => '',
            'deal_amount' => '',
        ]), $rep);

        $deal = $result->deal;
        $this->assertInstanceOf(Deal::class, $deal);
        $this->assertSame('Nora Fahad', $deal->title);
        $this->assertSame('0.00', $deal->amount);
        $this->assertNull($deal->account_id);
    }

    #[Test]
    public function a_lead_that_is_not_qualified_is_refused(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        try {
            app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest, $rep);
            $this->fail('A new lead was converted.');
        } catch (InvalidLeadTransitionException $exception) {
            $this->assertSame(__('leads.validation.not_qualified'), $exception->getMessage());
        }

        $this->assertFalse($lead->refresh()->isConverted());
        $this->assertSame(0, Contact::query()->count());
    }

    #[Test]
    public function a_converted_lead_cannot_be_converted_again(): void
    {
        $rep = $this->salesRep();
        $lead = $this->qualifiedLead($rep);
        $workflow = app(LeadConversionWorkflow::class);
        $workflow->convert($lead, new ConversionRequest(createDeal: false), $rep);

        try {
            $workflow->convert($lead, new ConversionRequest(createDeal: false), $rep);
            $this->fail('A converted lead was converted again.');
        } catch (InvalidLeadTransitionException $exception) {
            $this->assertSame(__('leads.validation.already_converted'), $exception->getMessage());
        }

        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(1, Account::query()->count());
    }

    #[Test]
    public function an_account_name_is_required_when_the_lead_has_no_company(): void
    {
        $rep = $this->salesRep();
        $lead = $this->qualifiedLead($rep, ['company_name' => null]);

        try {
            app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest(accountMode: ConversionRequest::ACCOUNT_NEW), $rep);
            $this->fail('An account without a name was created.');
        } catch (InvalidLeadTransitionException $exception) {
            $this->assertSame(__('leads.validation.account_name_required'), $exception->getMessage());
        }

        $this->assertSame(0, Account::query()->count());
        $this->assertFalse($lead->refresh()->isConverted());
    }

    #[Test]
    public function an_existing_account_or_contact_outside_the_actor_s_reach_is_refused(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $theirAccount = Account::factory()->create(['owner_id' => $other->getKey()]);
        $theirContact = Contact::factory()->create(['owner_id' => $other->getKey()]);
        $lead = $this->qualifiedLead($rep);
        $workflow = app(LeadConversionWorkflow::class);

        try {
            $workflow->convert($lead, new ConversionRequest(accountMode: ConversionRequest::ACCOUNT_EXISTING, accountId: $theirAccount->getKey()), $rep);
            $this->fail('An account outside the rep\'s reach was linked.');
        } catch (InvalidLeadTransitionException $exception) {
            $this->assertSame(__('leads.validation.account_not_accessible'), $exception->getMessage());
        }

        try {
            $workflow->convert($lead, new ConversionRequest(
                accountMode: ConversionRequest::ACCOUNT_NONE,
                contactMode: ConversionRequest::CONTACT_EXISTING,
                contactId: $theirContact->getKey(),
            ), $rep);
            $this->fail('A contact outside the rep\'s reach was linked.');
        } catch (InvalidLeadTransitionException $exception) {
            $this->assertSame(__('leads.validation.contact_not_accessible'), $exception->getMessage());
        }

        $this->assertFalse($lead->refresh()->isConverted());
        $this->assertNull($theirContact->refresh()->lead_id);
        $this->assertSame(1, Contact::query()->count());
    }

    #[Test]
    public function everything_rolls_back_when_the_deal_cannot_be_created(): void
    {
        $rep = $this->salesRep();
        $lead = $this->qualifiedLead($rep);
        $emptyPipeline = Pipeline::factory()->create();
        $statusLogs = LeadStatusLog::query()->where('lead_id', $lead->getKey())->count();

        try {
            app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest(
                accountMode: ConversionRequest::ACCOUNT_NEW,
                createDeal: true,
                pipelineId: $emptyPipeline->getKey(),
            ), $rep);
            $this->fail('A deal was created in a pipeline without a default stage.');
        } catch (InvalidLeadTransitionException $exception) {
            $this->assertSame(__('leads.validation.pipeline_has_no_default_stage'), $exception->getMessage());
        }

        $this->assertSame(0, Account::query()->count());
        $this->assertSame(0, Contact::query()->count());
        $this->assertSame(0, Deal::query()->count());
        $this->assertSame($statusLogs, LeadStatusLog::query()->where('lead_id', $lead->getKey())->count());
        $this->assertDatabaseMissing('activity_log', ['description' => ActivityLogEvent::LeadConverted->value]);

        $lead->refresh();
        $this->assertFalse($lead->isConverted());
        $this->assertSame(LeadStatusKind::Qualified, $lead->status->kind);
        $this->assertNull($lead->converted_contact_id);
    }

    #[Test]
    public function a_linked_contact_keeps_its_place_when_the_account_already_has_a_primary_contact(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $primary = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey(), 'is_primary' => true]);
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => null]);
        $lead = $this->qualifiedLead($rep);

        app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest(
            accountMode: ConversionRequest::ACCOUNT_EXISTING,
            accountId: $account->getKey(),
            contactMode: ConversionRequest::CONTACT_EXISTING,
            contactId: $contact->getKey(),
            createDeal: false,
        ), $rep);

        $this->assertSame($account->getKey(), $contact->refresh()->account_id);
        $this->assertFalse($contact->is_primary);
        $this->assertTrue($primary->refresh()->is_primary);
    }

    #[Test]
    public function a_deal_is_refused_in_an_inactive_pipeline_and_everything_rolls_back(): void
    {
        $rep = $this->salesRep();
        $lead = $this->qualifiedLead($rep);
        $retired = Pipeline::factory()->inactive()->create();
        PipelineStage::factory()->asDefault()->create(['pipeline_id' => $retired->getKey()]);
        $statusLogs = LeadStatusLog::query()->where('lead_id', $lead->getKey())->count();

        try {
            app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest(
                accountMode: ConversionRequest::ACCOUNT_NEW,
                createDeal: true,
                pipelineId: $retired->getKey(),
            ), $rep);
            $this->fail('A deal was created in an inactive pipeline.');
        } catch (InvalidLeadTransitionException $exception) {
            $this->assertSame(__('leads.validation.pipeline_inactive'), $exception->getMessage());
        }

        $this->assertSame(0, Account::query()->count());
        $this->assertSame(0, Contact::query()->count());
        $this->assertSame(0, Deal::query()->count());
        $this->assertSame($statusLogs, LeadStatusLog::query()->where('lead_id', $lead->getKey())->count());
        $this->assertDatabaseMissing('activity_log', ['description' => ActivityLogEvent::LeadConverted->value]);

        $lead->refresh();
        $this->assertFalse($lead->isConverted());
        $this->assertSame(LeadStatusKind::Qualified, $lead->status->kind);
        $this->assertNull($lead->converted_contact_id);
    }

    #[Test]
    public function the_converted_lead_is_frozen(): void
    {
        $rep = $this->salesRep();
        $admin = $this->admin();
        $lead = $this->qualifiedLead($rep);

        $this->assertTrue($rep->can('convert', $lead));
        $this->assertTrue($admin->can('convert', $lead));

        app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest(createDeal: false), $rep);

        foreach ([$rep, $admin] as $user) {
            $this->assertFalse($user->can('update', $lead));
            $this->assertFalse($user->can('changeStatus', $lead));
            $this->assertFalse($user->can('convert', $lead));
        }

        $this->expectException(InvalidLeadTransitionException::class);
        app(LeadStatusWorkflow::class)->transition($lead, $this->statusOfKind(LeadStatusKind::Working), $admin);
    }

    #[Test]
    public function only_convert_holders_within_reach_may_convert(): void
    {
        $team = $this->makeTeam();
        $rep = $this->salesRep($team);
        $manager = $this->salesManager($team);
        $outsider = $this->salesRep();
        $readOnly = $this->readOnly();
        $lead = $this->qualifiedLead($rep);

        $this->assertTrue($rep->can('convert', $lead));
        $this->assertTrue($manager->can('convert', $lead));
        $this->assertFalse($outsider->can('convert', $lead));
        $this->assertFalse($readOnly->can('convert', $lead));
    }

    #[Test]
    public function the_action_is_hidden_for_a_new_lead_and_for_a_read_only_user(): void
    {
        $rep = $this->salesRep();
        $newLead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $qualified = $this->qualifiedLead($rep);

        Livewire::actingAs($rep)
            ->test(ViewLead::class, ['record' => $newLead->getRouteKey()])
            ->assertActionHidden('convert');

        Livewire::actingAs($this->readOnly())
            ->test(ViewLead::class, ['record' => $qualified->getRouteKey()])
            ->assertActionHidden('convert');

        Livewire::actingAs($rep)
            ->test(ViewLead::class, ['record' => $qualified->getRouteKey()])
            ->assertActionVisible('convert');
    }

    #[Test]
    public function the_action_on_the_view_page_converts_and_redirects_to_the_contact(): void
    {
        $rep = $this->salesRep();
        $lead = $this->qualifiedLead($rep, ['first_name' => 'Huda', 'last_name' => 'Salem', 'company_name' => 'Nakhil Foods']);

        Livewire::actingAs($rep)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('convert', data: [
                'account_mode' => 'new',
                'account_name' => 'Nakhil Foods',
                'contact_mode' => 'new',
                'create_deal' => true,
                'deal_title' => 'Nakhil Foods – first order',
                'pipeline_id' => Pipeline::query()->where('is_default', true)->value('id'),
                'deal_amount' => '2500',
                'expected_close_date' => '2026-11-30',
                'note' => 'Converted from the view page.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified(__('leads.notifications.converted', ['name' => 'Huda Salem']))
            ->assertRedirect(ContactResource::getUrl('view', ['record' => $lead->refresh()->convertedContact]));

        $this->assertTrue($lead->isConverted());
        $this->assertSame('Nakhil Foods', $lead->convertedAccount?->name);
        $deal = $lead->convertedDeal;
        $this->assertInstanceOf(Deal::class, $deal);
        $this->assertSame('Nakhil Foods – first order', $deal->title);
        $this->assertSame('2500.00', $deal->amount);
    }

    #[Test]
    public function the_action_requires_an_account_name_and_an_existing_account_when_asked(): void
    {
        $rep = $this->salesRep();
        $lead = $this->qualifiedLead($rep, ['company_name' => null]);

        Livewire::actingAs($rep)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('convert', data: ['account_mode' => 'new', 'account_name' => '', 'contact_mode' => 'new', 'create_deal' => false])
            ->assertHasActionErrors(['account_name' => 'required']);

        Livewire::actingAs($rep)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('convert', data: ['account_mode' => 'existing', 'account_id' => null, 'contact_mode' => 'new', 'create_deal' => false])
            ->assertHasActionErrors(['account_id' => 'required']);

        // Another rep's account is not among the options the form offers, so it never reaches the workflow.
        $theirAccount = Account::factory()->create(['owner_id' => $this->salesRep()->getKey()]);

        Livewire::actingAs($rep)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('convert', data: ['account_mode' => 'existing', 'account_id' => $theirAccount->getKey(), 'contact_mode' => 'new', 'create_deal' => false])
            ->assertHasActionErrors(['account_id']);

        $this->assertFalse($lead->refresh()->isConverted());
        $this->assertSame(0, Contact::query()->count());
    }

    #[Test]
    public function the_action_defaults_to_the_existing_contact_with_the_same_email(): void
    {
        $rep = $this->salesRep();
        $existing = Contact::factory()->create(['owner_id' => $rep->getKey(), 'first_name' => 'Maha', 'last_name' => 'Known', 'email' => 'maha@example.com']);
        $lead = $this->qualifiedLead($rep, ['email' => 'MAHA@example.com']);

        $page = Livewire::actingAs($rep)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->mountAction('convert')
            ->assertActionDataSet([
                'contact_mode' => 'existing',
                'contact_id' => $existing->getKey(),
                'account_mode' => 'new',
                'create_deal' => true,
            ])
            ->instance();
        assert($page instanceof ViewLead);

        $schema = $page->getSchema((string) $page->getMountedActionSchemaName());
        $this->assertInstanceOf(Schema::class, $schema);

        $radio = $schema->getFlatComponents(withActions: false, withHidden: true)['contact_mode'] ?? null;
        $this->assertInstanceOf(Radio::class, $radio);

        $helper = collect($radio->getChildSchema('below_content')?->getComponents() ?? [])->first();
        $this->assertInstanceOf(Text::class, $helper);
        $this->assertSame(__('leads.helpers.contact_existing', ['name' => 'Maha Known']), (string) $helper->getContent());
    }

    #[Test]
    public function the_table_action_converts_without_redirecting_and_the_converted_tab_lists_the_lead(): void
    {
        $rep = $this->salesRep();
        $lead = $this->qualifiedLead($rep, ['company_name' => null]);

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->set('activeTab', 'qualified')
            ->callTableAction('convert', $lead, data: ['account_mode' => 'none', 'contact_mode' => 'new', 'create_deal' => false])
            ->assertHasNoTableActionErrors()
            ->assertNotified()
            ->assertNoRedirect();

        $this->assertTrue($lead->refresh()->isConverted());

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->set('activeTab', 'converted')
            ->assertCanSeeTableRecords([$lead])
            ->set('activeTab', 'open')
            ->assertCanNotSeeTableRecords([$lead]);
    }

    #[Test]
    public function the_action_reports_a_workflow_refusal_as_a_danger_notification(): void
    {
        $rep = $this->salesRep();
        $lead = $this->qualifiedLead($rep);
        $emptyPipeline = Pipeline::factory()->create();

        Livewire::actingAs($rep)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('convert', data: [
                'account_mode' => 'new',
                'account_name' => 'Rolled Back Ltd',
                'contact_mode' => 'new',
                'create_deal' => true,
                'deal_title' => 'Never created',
                'pipeline_id' => $emptyPipeline->getKey(),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified(__('leads.validation.pipeline_has_no_default_stage'))
            ->assertNoRedirect();

        $this->assertFalse($lead->refresh()->isConverted());
        $this->assertSame(0, Account::query()->count());
        $this->assertSame(0, Contact::query()->count());
        $this->assertSame(0, Deal::query()->count());
    }

    #[Test]
    public function the_view_page_shows_what_the_lead_became(): void
    {
        $rep = $this->salesRep();
        $lead = $this->qualifiedLead($rep, ['company_name' => 'Wadi Tech']);
        $result = app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest(createDeal: true, dealTitle: 'Wadi Tech – pilot'), $rep);

        Livewire::actingAs($rep)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->assertOk()
            ->assertSee(__('leads.sections.conversion'))
            ->assertSee('Wadi Tech')
            ->assertSee($result->contact->full_name)
            ->assertSee('Wadi Tech – pilot');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function qualifiedLead(User $owner, array $attributes = []): Lead
    {
        $lead = Lead::factory()->create($attributes + ['owner_id' => $owner->getKey()]);

        app(LeadStatusWorkflow::class)->transition($lead, $this->statusOfKind(LeadStatusKind::Qualified), $owner, 'Budget confirmed.');

        return $lead->refresh();
    }

    private function statusOfKind(LeadStatusKind $kind): LeadStatus
    {
        return LeadStatus::query()->where('kind', $kind->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
    }
}
