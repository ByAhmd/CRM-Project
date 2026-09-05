<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Enums\AccountType;
use App\Enums\ActivityLogEvent;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Tag;
use App\Services\Contacts\DuplicateFinder;
use App\Services\Contacts\RecordMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertNotified();

        $this->assertSame(3, Contact::query()->count());

        Livewire::actingAs($manager)
            ->test(ListContacts::class)
            ->callTableBulkAction('merge', [$a, $b], data: ['keep_id' => (string) $a->getKey()])
            ->assertNotified();

        $this->assertSoftDeleted('contacts', ['id' => $b->getKey()]);
        $this->assertNull($a->refresh()->deleted_at);
    }
}
