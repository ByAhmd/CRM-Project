<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\DealContactRole;
use App\Enums\LeadStatusKind;
use App\Exceptions\Leads\InvalidLeadTransitionException;
use App\Exceptions\Projects\CrossProjectLinkException;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Accounts\RelationManagers\DealsRelationManager;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\RelationManagers\ContactsRelationManager as DealContactsRelationManager;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\Contacts\DuplicateFinder;
use App\Services\Contacts\RecordMerger;
use App\Services\Leads\ConversionRequest;
use App\Services\Leads\LeadConversionWorkflow;
use App\Services\Leads\LeadStatusWorkflow;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The links between project-partitioned records (decision D-30): a contact's
 * account, a deal's account and contact, an account's parent, what a
 * conversion links and what a merge folds together all stay inside one
 * project — offered that way by the pickers, refused otherwise by the forms
 * and by the integrity guard — and duplicates are looked up inside one
 * project only.
 */
final class ProjectLinksTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Project $default;

    private Project $iqan;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $this->default = $this->defaultProject();
        $this->iqan = $this->makeProject();
        $this->user = $this->admin();
        $this->useProject($this->user, $this->iqan);
    }

    #[Test]
    public function the_contact_form_offers_only_the_accounts_of_the_project_the_form_is_about(): void
    {
        $iqanAccount = $this->account($this->iqan, 'Iqan Holdings');
        $defaultAccount = $this->account($this->default, 'Main Holdings');

        $this->assertSame([$iqanAccount->getKey()], $this->offeredBy(Livewire::actingAs($this->user)->test(CreateContact::class), 'account_id'), 'on create: the current project');

        // On edit the record's own project wins over the one the user is working in.
        $contact = Contact::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $this->user->getKey(), 'account_id' => null]);

        $this->assertSame([$defaultAccount->getKey()], $this->offeredBy(Livewire::actingAs($this->user)->test(EditContact::class, ['record' => $contact->getRouteKey()]), 'account_id'));
    }

    #[Test]
    public function the_deal_form_offers_only_the_accounts_and_contacts_of_the_project(): void
    {
        $iqanAccount = $this->account($this->iqan, 'Iqan Holdings');
        $this->account($this->default, 'Main Holdings');
        $iqanContact = Contact::factory()->create(['project_id' => $this->iqan->getKey(), 'owner_id' => $this->user->getKey(), 'account_id' => null]);
        Contact::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $this->user->getKey(), 'account_id' => null]);

        $page = Livewire::actingAs($this->user)->test(CreateDeal::class);

        $this->assertSame([$iqanAccount->getKey()], $this->offeredBy($page, 'account_id'));
        $this->assertSame([$iqanContact->getKey()], $this->offeredBy($page, 'contact_id'));
    }

    #[Test]
    public function the_account_form_offers_only_parents_of_the_project(): void
    {
        $iqanAccount = $this->account($this->iqan, 'Iqan Holdings');
        $mainHoldings = $this->account($this->default, 'Main Holdings');

        $this->assertSame([$iqanAccount->getKey()], $this->offeredBy(Livewire::actingAs($this->user)->test(CreateAccount::class), 'parent_account_id'));

        // Editing an account of the other project offers that project's accounts, never itself.
        $defaultChild = $this->account($this->default, 'Main Branch');
        $defaultParent = $this->account($this->default, 'Main Parent');

        $this->assertEqualsCanonicalizing(
            [$mainHoldings->getKey(), $defaultParent->getKey()],
            $this->offeredBy(Livewire::actingAs($this->user)->test(EditAccount::class, ['record' => $defaultChild->getRouteKey()]), 'parent_account_id'),
        );
    }

    #[Test]
    public function the_forms_refuse_a_crafted_link_to_another_project_with_the_validation_message(): void
    {
        $defaultAccount = $this->account($this->default, 'Main Holdings');
        $defaultContact = Contact::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $this->user->getKey(), 'account_id' => $defaultAccount->getKey()]);

        $contactForm = Livewire::actingAs($this->user)
            ->test(CreateContact::class)
            ->fillForm(['first_name' => 'نورة', 'last_name' => 'الحربي', 'account_id' => $defaultAccount->getKey()])
            ->call('create')
            ->assertHasFormErrors(['account_id'])
            ->assertSee(__('contacts.validation.account_project'));

        $this->assertContains(__('contacts.validation.account_project'), $contactForm->errors()->get('data.account_id'));
        $this->assertSame(1, Contact::query()->count(), 'nothing was created');

        $dealAccount = Livewire::actingAs($this->user)
            ->test(CreateDeal::class)
            ->fillForm(['title' => 'Crafted', 'amount' => 10, 'account_id' => $defaultAccount->getKey()])
            ->call('create')
            ->assertHasFormErrors(['account_id'])
            ->assertSee(__('deals.validation.account_project'));

        $this->assertContains(__('deals.validation.account_project'), $dealAccount->errors()->get('data.account_id'));

        $dealContact = Livewire::actingAs($this->user)
            ->test(CreateDeal::class)
            ->fillForm(['title' => 'Crafted', 'amount' => 10, 'contact_id' => $defaultContact->getKey()])
            ->call('create')
            ->assertHasFormErrors(['contact_id'])
            ->assertSee(__('deals.validation.contact_project'));

        $this->assertContains(__('deals.validation.contact_project'), $dealContact->errors()->get('data.contact_id'));
        $this->assertSame(0, Deal::query()->count());

        Livewire::actingAs($this->user)
            ->test(CreateAccount::class)
            ->fillForm(['name' => 'Crafted child', 'parent_account_id' => $defaultAccount->getKey()])
            ->call('create')
            ->assertHasFormErrors(['parent_account_id']);

        $this->assertSame(1, Account::query()->count());
    }

    #[Test]
    public function the_integrity_guard_refuses_a_cross_project_link_on_every_save(): void
    {
        $iqanAccount = $this->account($this->iqan, 'Iqan Holdings');
        $defaultAccount = $this->account($this->default, 'Main Holdings');
        $defaultContact = Contact::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $this->user->getKey(), 'account_id' => $defaultAccount->getKey()]);

        $this->assertRefused(
            fn () => Contact::factory()->create(['project_id' => $this->iqan->getKey(), 'account_id' => $defaultAccount->getKey()]),
            CrossProjectLinkException::LINK_ACCOUNT,
        );
        $this->assertRefused(
            fn () => Deal::factory()->create(['project_id' => $this->iqan->getKey(), 'account_id' => $defaultAccount->getKey()]),
            CrossProjectLinkException::LINK_ACCOUNT,
        );
        $this->assertRefused(
            fn () => Deal::factory()->create(['project_id' => $this->iqan->getKey(), 'account_id' => $iqanAccount->getKey(), 'contact_id' => $defaultContact->getKey()]),
            CrossProjectLinkException::LINK_CONTACT,
        );
        $this->assertRefused(
            fn () => Account::factory()->create(['project_id' => $this->iqan->getKey(), 'parent_account_id' => $defaultAccount->getKey()]),
            CrossProjectLinkException::LINK_PARENT,
        );

        // An update is guarded like a creation; the stored link stays.
        $iqanContact = Contact::factory()->create(['project_id' => $this->iqan->getKey(), 'account_id' => $iqanAccount->getKey()]);

        $this->assertRefused(fn () => $iqanContact->update(['account_id' => $defaultAccount->getKey()]), CrossProjectLinkException::LINK_ACCOUNT);
        $this->assertSame($iqanAccount->getKey(), $iqanContact->refresh()->account_id);

        $this->assertSame(1, Contact::query()->inProject($this->iqan)->count());
        $this->assertSame(0, Deal::query()->count());
        $this->assertSame(1, Account::query()->inProject($this->iqan)->count());
    }

    #[Test]
    public function a_record_created_from_a_parent_page_lands_in_the_parents_project(): void
    {
        // The user works in the default project and opens an «إيقان» account from a link.
        $this->useProject($this->user, $this->default);
        $iqanAccount = $this->account($this->iqan, 'Iqan Holdings');

        $manager = Livewire::actingAs($this->user)
            ->test(ContactsRelationManager::class, ['ownerRecord' => $iqanAccount, 'pageClass' => EditAccount::class])
            ->mountTableAction('create');

        // The create modal's badge names the account's project, not the user's.
        $instance = $manager->instance();
        $this->assertInstanceOf(HasActions::class, $instance);
        $this->assertInstanceOf(HasSchemas::class, $instance);
        $badge = $instance->getSchema((string) $instance->getMountedActionSchemaName())?->getFlatComponents(withActions: false, withHidden: true)['project_badge'] ?? null;
        $this->assertInstanceOf(TextEntry::class, $badge);
        $this->assertSame($this->iqan->display_name, (string) $badge->getState());

        $manager
            ->setTableActionData(['first_name' => 'خالد', 'last_name' => 'السالم', 'mobile' => '0559876543'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $contact = Contact::query()->where('last_name', 'السالم')->firstOrFail();
        $this->assertSame($iqanAccount->getKey(), $contact->account_id);
        $this->assertSame($this->iqan->getKey(), $contact->project_id, 'the contact follows the account, not the current project');

        // The tab lists the account's people although the user works in another project (D-30).
        $manager->assertCanSeeTableRecords([$contact]);

        $dealsManager = Livewire::actingAs($this->user)
            ->test(DealsRelationManager::class, ['ownerRecord' => $iqanAccount, 'pageClass' => EditAccount::class])
            ->callTableAction('create', data: ['title' => 'Iqan rollout', 'amount' => 5000])
            ->assertHasNoTableActionErrors();

        $deal = Deal::query()->where('title', 'Iqan rollout')->firstOrFail();
        $this->assertSame($iqanAccount->getKey(), $deal->account_id);
        $this->assertSame($this->iqan->getKey(), $deal->project_id);

        $dealsManager->assertCanSeeTableRecords([$deal]);

        // The same rule for any code that links a record without naming a project.
        $this->actingAs($this->user);
        $derived = Contact::query()->create(['first_name' => 'Derived', 'last_name' => 'Contact', 'account_id' => $iqanAccount->getKey(), 'owner_id' => $this->user->getKey()]);

        $this->assertSame($this->iqan->getKey(), $derived->project_id);
    }

    #[Test]
    public function the_deal_roles_tab_lists_and_offers_the_contacts_of_the_deals_project_whatever_project_is_current(): void
    {
        // The user works in the default project and opens an «إيقان» deal without an account from a link.
        $this->useProject($this->user, $this->default);
        $deal = Deal::factory()->create(['project_id' => $this->iqan->getKey(), 'owner_id' => $this->user->getKey(), 'account_id' => null]);
        $involved = Contact::factory()->create(['project_id' => $this->iqan->getKey(), 'owner_id' => $this->user->getKey(), 'account_id' => null, 'last_name' => 'Involved']);
        $deal->contacts()->attach($involved, ['role' => DealContactRole::Champion->value]);
        $iqanContact = Contact::factory()->create(['project_id' => $this->iqan->getKey(), 'owner_id' => $this->user->getKey(), 'account_id' => null, 'last_name' => 'Offered']);
        $defaultContact = Contact::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $this->user->getKey(), 'account_id' => null, 'last_name' => 'Elsewhere']);

        $manager = Livewire::actingAs($this->user)
            ->test(DealContactsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => EditDeal::class])
            // The role already given is listed, not hidden by the current project.
            ->assertCanSeeTableRecords([$involved])
            ->mountTableAction('attach');

        // The picker offers the deal's project only — never the current project's people.
        $instance = $manager->instance();
        $this->assertInstanceOf(HasActions::class, $instance);
        $this->assertInstanceOf(HasSchemas::class, $instance);
        $picker = $instance->getSchema((string) $instance->getMountedActionSchemaName())?->getFlatComponents(withActions: false, withHidden: true)['recordId'] ?? null;
        $this->assertInstanceOf(Select::class, $picker);
        $this->assertSame([$iqanContact->getKey()], array_map('intval', array_keys($picker->getOptions())));
        $this->assertSame([$iqanContact->getKey()], array_map('intval', array_keys($picker->getSearchResults('Offered'))));
        $this->assertSame([], $picker->getSearchResults('Elsewhere'));

        Livewire::actingAs($this->user)
            ->test(DealContactsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => EditDeal::class])
            ->callTableAction('attach', data: ['recordId' => $defaultContact->getKey()])
            ->assertHasTableActionErrors(['recordId']);

        $this->assertDatabaseMissing('deal_contacts', ['deal_id' => $deal->getKey(), 'contact_id' => $defaultContact->getKey()]);

        Livewire::actingAs($this->user)
            ->test(DealContactsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => EditDeal::class])
            ->callTableAction('attach', data: ['recordId' => $iqanContact->getKey(), 'role' => DealContactRole::Influencer->value])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('deal_contacts', ['deal_id' => $deal->getKey(), 'contact_id' => $iqanContact->getKey(), 'role' => DealContactRole::Influencer->value]);

        // The pivot itself is the last line of defence: a direct attach across projects is refused.
        $this->assertRefused(fn () => $deal->contacts()->attach($defaultContact), CrossProjectLinkException::LINK_CONTACT);
        $this->assertDatabaseMissing('deal_contacts', ['deal_id' => $deal->getKey(), 'contact_id' => $defaultContact->getKey()]);
        $this->assertSame(2, $deal->contacts()->count());
    }

    #[Test]
    public function a_record_never_changes_project_once_it_exists(): void
    {
        $owned = ['project_id' => $this->iqan->getKey(), 'owner_id' => $this->user->getKey()];
        $records = [
            Lead::factory()->create($owned),
            Contact::factory()->create($owned + ['account_id' => null]),
            Account::factory()->create($owned),
            Deal::factory()->create($owned + ['account_id' => null]),
        ];

        foreach ($records as $record) {
            $this->assertRefused(fn () => $record->update(['project_id' => $this->default->getKey()]), CrossProjectLinkException::LINK_PROJECT);
            $this->assertRefused(fn () => $record->forceFill(['project_id' => $this->default->getKey()])->save(), CrossProjectLinkException::LINK_PROJECT);
            $this->assertSame($this->iqan->getKey(), $record->refresh()->project_id, $record::class.' kept its project');

            // Writing the same project again is no move, and an unrelated edit saves as before.
            $record->update(['project_id' => $this->iqan->getKey(), 'description' => 'Still here']);
            $this->assertSame('Still here', $record->refresh()->description);
        }
    }

    #[Test]
    public function the_live_duplicate_warning_ignores_a_match_in_another_project_and_still_fires_inside_it(): void
    {
        Lead::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $this->user->getKey(), 'first_name' => 'Maha', 'last_name' => 'Elsewhere', 'email' => 'maha@example.com']);
        Contact::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $this->user->getKey(), 'first_name' => 'Maha', 'last_name' => 'Customer', 'email' => 'maha@example.com', 'account_id' => null]);

        Livewire::actingAs($this->user)
            ->test(CreateLead::class)
            ->fillForm(['first_name' => 'Maha', 'last_name' => 'New', 'email' => 'maha@example.com'])
            ->assertDontSee(__('merge.warnings.possible_duplicates'))
            ->assertDontSee('Maha Elsewhere')
            ->assertDontSee('Maha Customer')
            ->assertDontSee(__('merge.warnings.outside_reach'));

        Lead::factory()->create(['project_id' => $this->iqan->getKey(), 'owner_id' => $this->user->getKey(), 'first_name' => 'Maha', 'last_name' => 'Here', 'email' => 'maha@example.com']);

        Livewire::actingAs($this->user)
            ->test(CreateLead::class)
            ->fillForm(['first_name' => 'Maha', 'last_name' => 'New', 'email' => 'maha@example.com'])
            ->assertSee(__('merge.warnings.possible_duplicates'))
            ->assertSee('Maha Here')
            ->assertDontSee('Maha Elsewhere');

        // The anonymous "outside your reach" sentence is confined to the project too.
        $rep = $this->salesRep();
        $this->useProject($rep, $this->iqan);
        Contact::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $this->salesRep()->getKey(), 'email' => 'hidden@example.com', 'account_id' => null]);

        Livewire::actingAs($rep)
            ->test(CreateContact::class)
            ->fillForm(['first_name' => 'Hind', 'last_name' => 'Fresh', 'email' => 'hidden@example.com'])
            ->assertDontSee(__('merge.warnings.outside_reach'));

        Contact::factory()->create(['project_id' => $this->iqan->getKey(), 'owner_id' => $this->salesRep()->getKey(), 'email' => 'hidden@example.com', 'account_id' => null]);

        Livewire::actingAs($rep)
            ->test(CreateContact::class)
            ->fillForm(['first_name' => 'Hind', 'last_name' => 'Fresh', 'email' => 'hidden@example.com'])
            ->assertSee(__('merge.warnings.outside_reach'));
    }

    #[Test]
    public function the_finder_confines_a_lookup_to_the_named_project_else_the_viewers_current_one_else_nothing(): void
    {
        $inIqan = Contact::factory()->create(['project_id' => $this->iqan->getKey(), 'owner_id' => $this->user->getKey(), 'email' => 'same@example.com', 'account_id' => null]);
        $inDefault = Contact::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $this->user->getKey(), 'email' => 'same@example.com', 'account_id' => null]);
        $finder = app(DuplicateFinder::class);

        $this->assertSame([$inDefault->getKey()], $finder->contacts('same@example.com', null, null, $this->user, (int) $this->default->getKey())->modelKeys(), 'the named project');
        $this->assertSame([$inIqan->getKey()], $finder->contacts('same@example.com', null, null, $this->user)->modelKeys(), 'the viewer\'s current project');
        $this->assertEqualsCanonicalizing([$inIqan->getKey(), $inDefault->getKey()], $finder->contacts('same@example.com', null)->modelKeys(), 'organisation-wide for trusted callers');
        $this->assertSame([$inDefault->getKey()], $finder->contacts('same@example.com', null, null, null, (int) $this->default->getKey())->modelKeys(), 'a project without a viewer');
    }

    #[Test]
    public function merging_two_records_of_different_projects_is_refused_before_anything_changes(): void
    {
        $keep = Contact::factory()->create(['project_id' => $this->iqan->getKey(), 'owner_id' => $this->user->getKey(), 'account_id' => null, 'job_title' => null]);
        $duplicate = Contact::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $this->user->getKey(), 'account_id' => null, 'job_title' => 'CFO']);

        $this->assertRefused(fn () => app(RecordMerger::class)->mergeContacts($keep, $duplicate, $this->user), CrossProjectLinkException::LINK_MERGE);

        $this->assertNull($duplicate->refresh()->deleted_at);
        $this->assertNull($keep->refresh()->job_title);

        $keepAccount = $this->account($this->iqan, 'Iqan Holdings');
        $duplicateAccount = $this->account($this->default, 'Main Holdings');

        $this->assertRefused(fn () => app(RecordMerger::class)->mergeAccounts($keepAccount, $duplicateAccount, $this->user), CrossProjectLinkException::LINK_MERGE);

        $this->assertNull($duplicateAccount->refresh()->deleted_at);
    }

    #[Test]
    public function a_conversion_creates_the_account_contact_and_deal_in_the_leads_project(): void
    {
        // The user works in the default project; the lead's own project wins.
        $this->useProject($this->user, $this->default);
        $lead = $this->qualifiedLead($this->iqan, ['company_name' => 'شركة إيقان']);

        $result = app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest(
            accountMode: ConversionRequest::ACCOUNT_NEW,
            createDeal: true,
            dealTitle: 'إيقان – الطلب الأول',
        ), $this->user);

        $this->assertSame($this->iqan->getKey(), $result->account?->project_id);
        $this->assertSame($this->iqan->getKey(), $result->contact->project_id);
        $this->assertSame($this->iqan->getKey(), $result->deal?->project_id);
        $this->assertTrue($lead->refresh()->isConverted());
    }

    #[Test]
    public function a_conversion_refuses_to_link_an_existing_account_or_contact_of_another_project(): void
    {
        $lead = $this->qualifiedLead($this->iqan);
        $defaultAccount = $this->account($this->default, 'Main Holdings');
        $defaultContact = Contact::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $this->user->getKey(), 'account_id' => null]);
        $workflow = app(LeadConversionWorkflow::class);

        $this->assertTrue($this->user->can('view', $defaultAccount), 'precondition: only the project stands in the way');

        try {
            $workflow->convert($lead, new ConversionRequest(accountMode: ConversionRequest::ACCOUNT_EXISTING, accountId: $defaultAccount->getKey()), $this->user);
            $this->fail('An account of another project was linked.');
        } catch (InvalidLeadTransitionException $exception) {
            $this->assertSame(__('leads.validation.account_not_accessible'), $exception->getMessage());
        }

        try {
            $workflow->convert($lead, new ConversionRequest(
                accountMode: ConversionRequest::ACCOUNT_NONE,
                contactMode: ConversionRequest::CONTACT_EXISTING,
                contactId: $defaultContact->getKey(),
            ), $this->user);
            $this->fail('A contact of another project was linked.');
        } catch (InvalidLeadTransitionException $exception) {
            $this->assertSame(__('leads.validation.contact_not_accessible'), $exception->getMessage());
        }

        $this->assertFalse($lead->refresh()->isConverted());
        $this->assertNull($defaultContact->refresh()->lead_id);
        $this->assertSame(1, Contact::query()->count());

        // The same account in the lead's project links fine.
        $iqanAccount = $this->account($this->iqan, 'Iqan Holdings');
        $result = $workflow->convert($lead, new ConversionRequest(accountMode: ConversionRequest::ACCOUNT_EXISTING, accountId: $iqanAccount->getKey(), createDeal: false), $this->user);

        $this->assertSame($iqanAccount->getKey(), $result->account?->getKey());
        $this->assertSame($this->iqan->getKey(), $result->contact->project_id);
    }

    #[Test]
    public function the_conversion_modal_offers_and_suggests_only_records_of_the_leads_project(): void
    {
        // The user works in the default project; the modal still follows the lead.
        $this->useProject($this->user, $this->default);
        $lead = $this->qualifiedLead($this->iqan, ['email' => 'maha@example.com']);
        $iqanAccount = $this->account($this->iqan, 'Horizon Iqan');
        $this->account($this->default, 'Horizon Main');
        $iqanContact = Contact::factory()->create(['project_id' => $this->iqan->getKey(), 'owner_id' => $this->user->getKey(), 'first_name' => 'Maha', 'last_name' => 'Known', 'email' => 'other@example.com', 'account_id' => null]);
        Contact::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $this->user->getKey(), 'first_name' => 'Maha', 'last_name' => 'Elsewhere', 'email' => 'maha@example.com', 'account_id' => null]);

        $page = Livewire::actingAs($this->user)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->mountAction('convert')
            // The same e-mail in another project is not a duplicate: no existing contact is suggested.
            ->assertActionDataSet(['contact_mode' => ConversionRequest::CONTACT_NEW, 'contact_id' => null])
            ->instance();
        assert($page instanceof ViewLead);

        $schema = $page->getSchema((string) $page->getMountedActionSchemaName());
        $this->assertInstanceOf(Schema::class, $schema);
        $components = $schema->getFlatComponents(withActions: false, withHidden: true);

        $accounts = $components['account_id'] ?? null;
        $this->assertInstanceOf(Select::class, $accounts);
        $this->assertSame([$iqanAccount->getKey() => 'Horizon Iqan'], $accounts->getSearchResults('Horizon'));

        $contacts = $components['contact_id'] ?? null;
        $this->assertInstanceOf(Select::class, $contacts);
        $this->assertSame([$iqanContact->getKey() => 'Maha Known'], $contacts->getSearchResults('Maha'));
    }

    /**
     * The keys the select offers, in option order.
     *
     * @return list<int>
     */
    private function offeredBy(Testable $component, string $field): array
    {
        $offered = [];

        $component->assertFormFieldExists($field, static function (Select $select) use (&$offered): bool {
            $offered = array_map('intval', array_keys($select->getOptions()));

            return true;
        });

        return $offered;
    }

    private function account(Project $project, string $name): Account
    {
        return Account::factory()->create(['project_id' => $project->getKey(), 'owner_id' => $this->user->getKey(), 'name' => $name]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function qualifiedLead(Project $project, array $attributes = []): Lead
    {
        $lead = Lead::factory()->create($attributes + ['project_id' => $project->getKey(), 'owner_id' => $this->user->getKey()]);

        app(LeadStatusWorkflow::class)->transition($lead, $this->statusOfKind(LeadStatusKind::Qualified), $this->user, 'Budget confirmed.');

        return $lead->refresh();
    }

    /**
     * @param  callable(): mixed  $attempt
     * @param  CrossProjectLinkException::LINK_*  $link
     */
    private function assertRefused(callable $attempt, string $link): void
    {
        try {
            $attempt();
        } catch (CrossProjectLinkException $exception) {
            $this->assertSame($link, $exception->link);

            return;
        }

        $this->fail("A cross-project {$link} link was accepted.");
    }
}
