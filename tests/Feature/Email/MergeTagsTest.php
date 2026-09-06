<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\CrmRole;
use App\Enums\CustomFieldEntity;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Services\Email\MergeTags;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The merge tags a template may use (decision D-10): which are offered per
 * entity and what they resolve to for one recipient and one sender.
 */
final class MergeTagsTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
    }

    #[Test]
    public function the_available_tags_depend_on_the_entity_and_carry_translated_labels(): void
    {
        $contact = MergeTags::available(CustomFieldEntity::Contact);
        $lead = MergeTags::available(CustomFieldEntity::Lead);
        $account = MergeTags::available(CustomFieldEntity::Account);

        $this->assertSame([
            'contact.first_name', 'contact.last_name', 'contact.full_name', 'contact.job_title',
            'contact.email', 'contact.mobile', 'account.name',
            'user.name', 'user.email', 'organisation.name', 'date.today',
        ], array_keys($contact));

        $this->assertSame([
            'lead.first_name', 'lead.last_name', 'lead.full_name', 'lead.company_name',
            'lead.job_title', 'lead.email', 'lead.phone',
            'user.name', 'user.email', 'organisation.name', 'date.today',
        ], array_keys($lead));

        $this->assertSame(['user.name', 'user.email', 'organisation.name', 'date.today'], array_keys($account));

        foreach ([...$contact, ...$lead] as $tag => $label) {
            $this->assertNotSame('', $label);
            $this->assertStringNotContainsString('email.tags.', $label, "tag {$tag} has no label");
        }

        $this->assertSame([CustomFieldEntity::Lead, CustomFieldEntity::Contact], MergeTags::supportedEntities());
    }

    #[Test]
    public function contact_tags_resolve_to_the_contact_the_account_the_sender_the_organisation_and_the_date(): void
    {
        $this->travelTo(Carbon::parse('2026-09-06 23:30:00', 'UTC'));

        $rep = $this->makeUser(CrmRole::SalesRep, ['name' => 'Nora Rep', 'email' => 'nora@example.com']);
        $account = Account::factory()->create(['name' => 'Acme Trading', 'owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create([
            'first_name' => 'Sara',
            'last_name' => 'Otaibi',
            'job_title' => 'Procurement manager',
            'email' => 'sara@acme.test',
            'mobile' => '0551234567',
            'account_id' => $account->getKey(),
            'owner_id' => $rep->getKey(),
        ]);

        app(SettingsRepository::class)->update([
            SettingsRepository::ORGANISATION_NAME => 'ZonKSA',
            SettingsRepository::TIMEZONE => 'Asia/Riyadh',
        ]);

        $values = app(MergeTags::class)->resolve($contact, $rep);

        $this->assertSame('Sara', $values['contact.first_name']);
        $this->assertSame('Otaibi', $values['contact.last_name']);
        $this->assertSame('Sara Otaibi', $values['contact.full_name']);
        $this->assertSame('Procurement manager', $values['contact.job_title']);
        $this->assertSame('sara@acme.test', $values['contact.email']);
        $this->assertSame('0551234567', $values['contact.mobile']);
        $this->assertSame('Acme Trading', $values['account.name']);
        $this->assertSame('Nora Rep', $values['user.name']);
        $this->assertSame('nora@example.com', $values['user.email']);
        $this->assertSame('ZonKSA', $values['organisation.name']);
        // 23:30 UTC is already the next day in Riyadh (UTC+3).
        $this->assertSame('2026-09-07', $values['date.today']);
        $this->assertSame(array_keys(MergeTags::available(CustomFieldEntity::Contact)), array_keys($values));
    }

    #[Test]
    public function missing_values_resolve_to_an_empty_string(): void
    {
        $rep = $this->salesRep();
        $contact = Contact::factory()->create([
            'job_title' => null,
            'mobile' => null,
            'account_id' => null,
            'owner_id' => $rep->getKey(),
        ]);

        $values = app(MergeTags::class)->resolve($contact, $rep);

        $this->assertSame('', $values['contact.job_title']);
        $this->assertSame('', $values['contact.mobile']);
        $this->assertSame('', $values['account.name']);
    }

    #[Test]
    public function lead_tags_resolve_to_the_lead(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create([
            'first_name' => 'Khalid',
            'last_name' => 'Harbi',
            'company_name' => 'Harbi Logistics',
            'job_title' => 'CEO',
            'email' => 'khalid@harbi.test',
            'phone' => '0509876543',
            'owner_id' => $rep->getKey(),
        ]);

        $values = app(MergeTags::class)->resolve($lead, $rep);

        $this->assertSame('Khalid', $values['lead.first_name']);
        $this->assertSame('Harbi', $values['lead.last_name']);
        $this->assertSame('Khalid Harbi', $values['lead.full_name']);
        $this->assertSame('Harbi Logistics', $values['lead.company_name']);
        $this->assertSame('CEO', $values['lead.job_title']);
        $this->assertSame('khalid@harbi.test', $values['lead.email']);
        $this->assertSame('0509876543', $values['lead.phone']);
        $this->assertSame($rep->name, $values['user.name']);
        $this->assertArrayNotHasKey('contact.first_name', $values);
        $this->assertSame(array_keys(MergeTags::available(CustomFieldEntity::Lead)), array_keys($values));
    }

    #[Test]
    public function only_contacts_and_leads_are_recipients(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);

        $this->assertSame(CustomFieldEntity::Contact, MergeTags::entityFor(Contact::factory()->create(['owner_id' => $rep->getKey()])));
        $this->assertSame(CustomFieldEntity::Lead, MergeTags::entityFor(Lead::factory()->create(['owner_id' => $rep->getKey()])));

        try {
            MergeTags::entityFor($account);
            $this->fail('An account was accepted as a recipient.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(__('email.validation.unsupported_recipient'), $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        app(MergeTags::class)->resolve($account, $rep);
    }
}
