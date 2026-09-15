<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Enums\AccountType;
use App\Enums\ActivityLogEvent;
use App\Enums\CustomFieldEntity;
use App\Enums\DealContactRole;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Models\Account;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Note;
use App\Models\Tag;
use App\Models\Task;
use App\Services\Contacts\DuplicateFinder;
use App\Services\Contacts\RecordMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Duplicate detection warns on normalised email/phone; merging folds one
 * record into another (decision A-11).
 */
final class DuplicateAndMergeTest extends TestCase
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
    public function the_finder_matches_on_normalised_email_and_phone_only(): void
    {
        $existing = Contact::factory()->create(['email' => 'Sara@Example.com', 'mobile' => '0551234567']);
        Contact::factory()->create(['email' => 'other@example.com', 'mobile' => '0500000000']);

        $finder = app(DuplicateFinder::class);

        $this->assertTrue($finder->contacts('sara@example.com', null)->contains($existing));
        $this->assertTrue($finder->contacts(null, '+966 55 123 4567')->contains($existing));
        $this->assertCount(1, $finder->contacts('SARA@EXAMPLE.COM', '00966551234567'));
        $this->assertCount(0, $finder->contacts('nobody@example.com', '0511111111'));
        $this->assertCount(0, $finder->contacts('sara@example.com', null, excludeId: $existing->getKey()));
    }

    #[Test]
    public function the_finder_matches_accounts_on_name_case_insensitively(): void
    {
        $existing = Account::factory()->create(['name' => 'Horizon Trading', 'email' => 'info@horizon.sa']);

        $finder = app(DuplicateFinder::class);

        $this->assertTrue($finder->accounts('horizon trading', null, null)->contains($existing));
        $this->assertTrue($finder->accounts(null, 'INFO@horizon.sa', null)->contains($existing));
        $this->assertCount(0, $finder->accounts('Other Co', 'x@y.z', '0500000000'));
    }

    #[Test]
    public function the_create_form_warns_about_a_possible_duplicate_without_blocking(): void
    {
        $rep = $this->salesRep();
        Contact::factory()->create(['first_name' => 'Sara', 'last_name' => 'Duplicate', 'email' => 'sara@example.com', 'owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(CreateContact::class)
            ->fillForm(['first_name' => 'Sara', 'last_name' => 'Again', 'email' => 'sara@example.com'])
            ->assertSee('Sara Duplicate')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Contact::query()->where('email_normalized', 'sara@example.com')->count());
    }

    #[Test]
    public function merging_contacts_fills_blanks_unions_tags_and_soft_deletes_the_duplicate(): void
    {
        $manager = $this->salesManager();
        $account = Account::factory()->create(['owner_id' => $manager->getKey()]);
        $tagA = Tag::factory()->create();
        $tagB = Tag::factory()->create();

        $keep = Contact::factory()->create(['owner_id' => $manager->getKey(), 'job_title' => null, 'mobile' => null, 'account_id' => null]);
        $keep->tags()->attach($tagA);
        $duplicate = Contact::factory()->create(['owner_id' => $manager->getKey(), 'job_title' => 'CFO', 'mobile' => '0551112222', 'account_id' => $account->getKey(), 'is_primary' => true]);
        $duplicate->tags()->attach($tagB);

        app(RecordMerger::class)->mergeContacts($keep, $duplicate, $manager);

        $keep->refresh();

        $this->assertSame('CFO', $keep->job_title);
        $this->assertSame('0551112222', $keep->mobile);
        $this->assertSame($account->getKey(), $keep->account_id);
        $this->assertEqualsCanonicalizing([$tagA->getKey(), $tagB->getKey()], $keep->tags()->pluck('tags.id')->all());
        $this->assertSoftDeleted('contacts', ['id' => $duplicate->getKey()]);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::ContactMerged->value, 'subject_id' => $keep->getKey()]);
    }

    #[Test]
    public function merging_accounts_moves_the_contacts_and_keeps_customer_status(): void
    {
        $manager = $this->salesManager();
        $keep = Account::factory()->create(['owner_id' => $manager->getKey(), 'website' => null]);
        $duplicate = Account::factory()->customer()->create(['owner_id' => $manager->getKey(), 'website' => 'https://dup.example']);
        $contact = Contact::factory()->create(['account_id' => $duplicate->getKey(), 'owner_id' => $manager->getKey()]);

        app(RecordMerger::class)->mergeAccounts($keep, $duplicate, $manager);

        $keep->refresh();

        $this->assertSame(AccountType::Customer, $keep->type);
        $this->assertSame('https://dup.example', $keep->website);
        $this->assertSame($keep->getKey(), $contact->refresh()->account_id);
        $this->assertSoftDeleted('accounts', ['id' => $duplicate->getKey()]);
    }

    #[Test]
    public function the_merge_bulk_action_requires_merge_permission_and_exactly_two_records(): void
    {
        $rep = $this->salesRep();
        $manager = $this->salesManager();
        $a = Contact::factory()->create(['owner_id' => $manager->getKey()]);
        $b = Contact::factory()->create(['owner_id' => $manager->getKey()]);
        $c = Contact::factory()->create(['owner_id' => $manager->getKey()]);

        $this->assertFalse($rep->can('merge', $a));
        $this->assertTrue($manager->can('merge', $a));

        Livewire::actingAs($manager)
            ->test(ListContacts::class)
            ->callTableBulkAction('merge', [$a, $b, $c], data: ['keep_id' => (string) $a->getKey()])
            ->assertNotified(__('merge.validation.exactly_two'));

        $this->assertSame(3, Contact::query()->count());

        Livewire::actingAs($manager)
            ->test(ListContacts::class)
            ->callTableBulkAction('merge', [$a, $b], data: ['keep_id' => (string) $a->getKey()])
            ->assertNotified(__('merge.notifications.done'));

        $this->assertSoftDeleted('contacts', ['id' => $b->getKey()]);
        $this->assertNull($a->refresh()->deleted_at);
    }

    #[Test]
    public function a_sales_rep_without_merge_permission_cannot_merge_from_the_table(): void
    {
        $rep = $this->salesRep();
        $first = Contact::factory()->create(['owner_id' => $rep->getKey()]);
        $second = Contact::factory()->create(['owner_id' => $rep->getKey()]);

        $this->assertTrue($rep->can('update', $first));
        $this->assertFalse($rep->can('merge', $first));

        Livewire::actingAs($rep)
            ->test(ListContacts::class)
            ->callTableBulkAction('merge', [$first, $second], data: ['keep_id' => (string) $first->getKey()]);

        $this->assertNull($first->refresh()->deleted_at);
        $this->assertNull($second->refresh()->deleted_at);
        $this->assertDatabaseMissing('activity_log', ['description' => ActivityLogEvent::ContactMerged->value]);
    }

    #[Test]
    public function merging_accounts_relocates_every_child_to_the_surviving_account(): void
    {
        $this->seedLookups();
        $manager = $this->salesManager();
        $keep = Account::factory()->create(['owner_id' => $manager->getKey()]);
        $duplicate = Account::factory()->create(['owner_id' => $manager->getKey()]);
        $keep->forceFill(['parent_account_id' => $duplicate->getKey()])->save();
        $child = Account::factory()->create(['owner_id' => $manager->getKey(), 'parent_account_id' => $duplicate->getKey()]);

        $contact = Contact::factory()->create(['account_id' => $duplicate->getKey(), 'owner_id' => $manager->getKey()]);
        $trashedContact = Contact::factory()->create(['account_id' => $duplicate->getKey(), 'owner_id' => $manager->getKey()]);
        $trashedContact->delete();
        $deal = Deal::factory()->create(['account_id' => $duplicate->getKey(), 'owner_id' => $manager->getKey()]);
        $trashedDeal = Deal::factory()->create(['account_id' => $duplicate->getKey(), 'owner_id' => $manager->getKey()]);
        $trashedDeal->delete();
        $task = Task::factory()->create(['account_id' => $duplicate->getKey(), 'assignee_id' => $manager->getKey()]);
        $note = Note::factory()->create(['account_id' => $duplicate->getKey(), 'author_id' => $manager->getKey()]);
        $activity = Activity::factory()->create(['lead_id' => null, 'account_id' => $duplicate->getKey(), 'owner_id' => $manager->getKey()]);
        $lead = Lead::factory()->create(['owner_id' => $manager->getKey(), 'converted_account_id' => $duplicate->getKey()]);
        $attachment = Attachment::factory()->forSubject($duplicate)->create(['uploaded_by' => $manager->getKey()]);

        $sharedField = CustomField::factory()->create(['entity' => CustomFieldEntity::Account, 'key' => 'region_code']);
        $onlyOnDuplicate = CustomField::factory()->create(['entity' => CustomFieldEntity::Account, 'key' => 'erp_number']);
        CustomFieldValue::factory()->forRecord($keep)->forField($sharedField)->create(['value_string' => 'kept']);
        $shadowed = CustomFieldValue::factory()->forRecord($duplicate)->forField($sharedField)->create(['value_string' => 'shadowed']);
        $moved = CustomFieldValue::factory()->forRecord($duplicate)->forField($onlyOnDuplicate)->create(['value_string' => 'ERP-7']);

        app(RecordMerger::class)->mergeAccounts($keep, $duplicate, $manager);

        $this->assertNull($keep->refresh()->parent_account_id, 'The survivor must never keep the trashed duplicate as its parent.');
        $this->assertSame($keep->getKey(), $child->refresh()->parent_account_id);
        $this->assertSame($keep->getKey(), $contact->refresh()->account_id);
        $this->assertSame($keep->getKey(), Contact::withTrashed()->findOrFail($trashedContact->getKey())->account_id);
        $this->assertSame($keep->getKey(), $deal->refresh()->account_id);
        $this->assertSame($keep->getKey(), Deal::withTrashed()->findOrFail($trashedDeal->getKey())->account_id);
        $this->assertSame($keep->getKey(), $task->refresh()->account_id);
        $this->assertSame($keep->getKey(), $note->refresh()->account_id);
        $this->assertSame($keep->getKey(), $activity->refresh()->account_id);
        $this->assertSame($keep->getKey(), $lead->refresh()->converted_account_id);
        $this->assertSame($keep->getKey(), $attachment->refresh()->attachable_id);
        $this->assertSame($keep->getMorphClass(), $attachment->attachable_type);
        $this->assertSame($keep->getKey(), $moved->refresh()->entity_id);
        $this->assertSame($duplicate->getKey(), $shadowed->refresh()->entity_id, 'The kept value wins; the duplicate value stays on the trashed row.');
        $this->assertSame('kept', $keep->refresh()->customField('region_code'));
        $this->assertSame('ERP-7', $keep->customField('erp_number'));
        $this->assertSoftDeleted('accounts', ['id' => $duplicate->getKey()]);

        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::DealUpdated->value,
            'subject_type' => Deal::class,
            'subject_id' => $deal->getKey(),
        ]);

        $merge = ActivityLog::query()->where('description', ActivityLogEvent::AccountMerged->value)->firstOrFail();
        $relocated = (array) $merge->getExtraProperty('relocated');

        $this->assertEqualsCanonicalizing([$deal->getKey(), $trashedDeal->getKey()], $relocated['deals']);
        $this->assertSame([$activity->getKey()], $relocated['activities']);
        $this->assertSame([$lead->getKey()], $relocated['converted_leads']);
        $this->assertSame([$child->getKey()], $relocated['child_accounts']);
    }

    #[Test]
    public function merging_contacts_relocates_every_child_and_deduplicates_deal_roles(): void
    {
        $this->seedLookups();
        $manager = $this->salesManager();
        $account = Account::factory()->create(['owner_id' => $manager->getKey()]);
        $keep = Contact::factory()->create(['account_id' => $account->getKey(), 'owner_id' => $manager->getKey()]);
        $duplicate = Contact::factory()->create(['account_id' => $account->getKey(), 'owner_id' => $manager->getKey()]);

        $primaryDeal = Deal::factory()->create(['account_id' => $account->getKey(), 'contact_id' => $duplicate->getKey(), 'owner_id' => $manager->getKey()]);
        $primaryDeal->contacts()->attach($duplicate->getKey(), ['role' => DealContactRole::DecisionMaker->value]);
        $sharedDeal = Deal::factory()->create(['account_id' => $account->getKey(), 'owner_id' => $manager->getKey()]);
        $sharedDeal->contacts()->attach($keep->getKey(), ['role' => null]);
        $sharedDeal->contacts()->attach($duplicate->getKey(), ['role' => DealContactRole::Champion->value]);
        $rolesDeal = Deal::factory()->create(['account_id' => $account->getKey(), 'owner_id' => $manager->getKey()]);
        $rolesDeal->contacts()->attach($keep->getKey(), ['role' => DealContactRole::Influencer->value]);
        $rolesDeal->contacts()->attach($duplicate->getKey(), ['role' => DealContactRole::EndUser->value]);

        $task = Task::factory()->create(['contact_id' => $duplicate->getKey(), 'assignee_id' => $manager->getKey()]);
        $trashedNote = Note::factory()->create(['contact_id' => $duplicate->getKey(), 'author_id' => $manager->getKey()]);
        $trashedNote->delete();
        $activity = Activity::factory()->create(['lead_id' => null, 'contact_id' => $duplicate->getKey(), 'owner_id' => $manager->getKey()]);
        $lead = Lead::factory()->create(['owner_id' => $manager->getKey(), 'converted_contact_id' => $duplicate->getKey()]);
        $attachment = Attachment::factory()->forSubject($duplicate)->create(['uploaded_by' => $manager->getKey()]);
        $field = CustomField::factory()->create(['entity' => CustomFieldEntity::Contact, 'key' => 'nickname']);
        $value = CustomFieldValue::factory()->forRecord($duplicate)->forField($field)->create(['value_string' => 'Abu Khalid']);

        app(RecordMerger::class)->mergeContacts($keep, $duplicate, $manager);

        $this->assertSame($keep->getKey(), $primaryDeal->refresh()->contact_id);
        $this->assertSame(DealContactRole::DecisionMaker->value, $this->roleOf($primaryDeal, $keep));
        $this->assertSame(DealContactRole::Champion->value, $this->roleOf($sharedDeal, $keep), 'A blank kept role is filled from the duplicate.');
        $this->assertSame(DealContactRole::Influencer->value, $this->roleOf($rolesDeal, $keep), 'The kept role wins.');
        $this->assertSame(0, DB::table('deal_contacts')->where('contact_id', $duplicate->getKey())->count());
        $this->assertSame(1, DB::table('deal_contacts')->where('deal_id', $sharedDeal->getKey())->count());
        $this->assertSame($keep->getKey(), $task->refresh()->contact_id);
        $this->assertSame($keep->getKey(), Note::withTrashed()->findOrFail($trashedNote->getKey())->contact_id);
        $this->assertSame($keep->getKey(), $activity->refresh()->contact_id);
        $this->assertSame($keep->getKey(), $lead->refresh()->converted_contact_id);
        $this->assertSame($keep->getKey(), $attachment->refresh()->attachable_id);
        $this->assertSame($keep->getKey(), $value->refresh()->entity_id);
        $this->assertSoftDeleted('contacts', ['id' => $duplicate->getKey()]);

        $merge = ActivityLog::query()->where('description', ActivityLogEvent::ContactMerged->value)->firstOrFail();
        $relocated = (array) $merge->getExtraProperty('relocated');

        $this->assertSame([$primaryDeal->getKey()], $relocated['deals']);
        $this->assertEqualsCanonicalizing([$primaryDeal->getKey(), $sharedDeal->getKey(), $rolesDeal->getKey()], $relocated['deal_roles']);
        $this->assertSame([$task->getKey()], $relocated['tasks']);
    }

    #[Test]
    public function a_merge_whose_relocation_fails_changes_nothing(): void
    {
        $this->seedLookups();
        $manager = $this->salesManager();
        $keep = Account::factory()->create(['owner_id' => $manager->getKey(), 'website' => null]);
        $duplicate = Account::factory()->create(['owner_id' => $manager->getKey(), 'website' => 'https://dup.example']);
        $contact = Contact::factory()->create(['account_id' => $duplicate->getKey(), 'owner_id' => $manager->getKey()]);
        Deal::factory()->create(['account_id' => $duplicate->getKey(), 'owner_id' => $manager->getKey()]);

        Deal::updating(static function (): never {
            throw new \RuntimeException('simulated relocation failure');
        });

        try {
            app(RecordMerger::class)->mergeAccounts($keep, $duplicate, $manager);
            $this->fail('The simulated failure did not surface.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated relocation failure', $exception->getMessage());
        } finally {
            Deal::flushEventListeners();
        }

        $this->assertSame($duplicate->getKey(), $contact->refresh()->account_id);
        $this->assertNull($duplicate->refresh()->deleted_at);
        $this->assertNull($keep->refresh()->website);
        $this->assertDatabaseMissing('activity_log', ['description' => ActivityLogEvent::AccountMerged->value]);
    }

    private function roleOf(Deal $deal, Contact $contact): ?string
    {
        $role = DB::table('deal_contacts')
            ->where('deal_id', $deal->getKey())
            ->where('contact_id', $contact->getKey())
            ->value('role');

        return is_string($role) ? $role : null;
    }
}
