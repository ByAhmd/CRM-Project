<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\ActivityDirection;
use App\Enums\ActivityKind;
use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Mail\CrmMessage;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Services\Email\EmailSendService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The "Send email" action (decision D-10) on the contact and lead pages and
 * tables: the template prefills the editable text in the recipient's
 * language, sending records the activity, queues the message and notifies,
 * and the action is disabled without an address and hidden without the
 * permission.
 */
final class SendEmailActionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        Mail::fake();
    }

    #[Test]
    public function the_modal_prefills_the_subject_and_body_from_the_template_in_the_recipient_locale_and_sends(): void
    {
        $rep = $this->makeUser(CrmRole::SalesRep, ['name' => 'Nora Rep', 'email' => 'nora@example.com']);
        $contact = Contact::factory()->create([
            'first_name' => 'Sara',
            'last_name' => 'Otaibi',
            'email' => 'sara@acme.test',
            'preferred_locale' => 'en',
            'owner_id' => $rep->getKey(),
        ]);
        $template = EmailTemplate::factory()->forContacts()->create([
            'subject_ar' => 'مرحباً {{contact.first_name}}',
            'subject_en' => 'Hello {{contact.first_name}}',
            'body_ar' => 'عزيزي {{contact.full_name}}',
            'body_en' => "Dear {{contact.full_name}},\nfrom {{user.name}}",
        ]);
        $leadOnly = EmailTemplate::factory()->forLeads()->create();
        $inactive = EmailTemplate::factory()->forContacts()->inactive()->create();

        $page = Livewire::actingAs($rep)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->assertActionVisible('sendEmail')
            ->assertActionEnabled('sendEmail')
            ->mountAction('sendEmail')
            ->assertActionDataSet(['locale' => 'en', 'to' => 'sara@acme.test', 'subject' => '', 'body' => ''])
            ->assertSchemaComponentExists('template_id', 'mountedActionSchema0')
            ->setActionData(['template_id' => $template->getKey()])
            ->assertActionDataSet(['subject' => 'Hello Sara', 'body' => "Dear Sara Otaibi,\nfrom Nora Rep"])
            ->setActionData(['locale' => 'ar'])
            ->assertActionDataSet(['subject' => 'مرحباً Sara', 'body' => 'عزيزي Sara Otaibi'])
            ->setActionData(['locale' => 'en'])
            ->assertActionDataSet(['subject' => 'Hello Sara']);

        $instance = $page->instance();
        assert($instance instanceof ViewContact);

        $select = $instance->getSchema('mountedActionSchema0')?->getComponent('template_id');
        assert($select instanceof Select);

        $options = $select->getOptions();

        $this->assertArrayHasKey($template->getKey(), $options);
        $this->assertArrayNotHasKey($leadOnly->getKey(), $options);
        $this->assertArrayNotHasKey($inactive->getKey(), $options);

        $page
            ->setActionData(['subject' => 'Hello {{contact.first_name}}, edited', 'body' => 'Edited body for {{account.name}}'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified(__('email.notifications.sent', ['to' => 'sara@acme.test']));

        $activity = Activity::query()->latest('id')->firstOrFail();

        $this->assertSame(ActivityKind::Email, $activity->kind);
        $this->assertSame(ActivityDirection::Outbound, $activity->direction);
        $this->assertSame('Hello Sara, edited', $activity->subject);
        $this->assertSame('Edited body for', trim((string) $activity->body));
        $this->assertSame($contact->getKey(), $activity->contact_id);
        $this->assertSame($template->getKey(), $activity->payload['template_id'] ?? null);
        $this->assertSame('en', $activity->payload['locale'] ?? null);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::EmailSent->value, 'subject_id' => $contact->getKey()]);

        Mail::assertQueued(CrmMessage::class, fn (CrmMessage $mail): bool => $mail->hasTo('sara@acme.test')
            && $mail->hasReplyTo('nora@example.com')
            && $mail->locale === 'en'
            && $mail->messageSubject === 'Hello Sara, edited');
    }

    #[Test]
    public function the_subject_and_body_are_required_and_bounded(): void
    {
        $rep = $this->salesRep();
        $contact = Contact::factory()->create(['owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->callAction('sendEmail', data: ['locale' => 'ar', 'subject' => '', 'body' => ''])
            ->assertHasActionErrors(['subject' => 'required', 'body' => 'required']);

        Livewire::actingAs($rep)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->callAction('sendEmail', data: ['locale' => 'fr', 'subject' => str_repeat('s', 201), 'body' => 'Body'])
            ->assertHasActionErrors(['subject' => 'max', 'locale']);

        $this->assertSame(0, Activity::query()->count());
        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_subject_that_outgrows_the_limit_once_rendered_shows_a_warning_and_is_refused_with_a_notification(): void
    {
        $rep = $this->salesRep();
        $contact = Contact::factory()->create([
            'first_name' => str_repeat('S', 40),
            'last_name' => 'Otaibi',
            'email' => 'sara@acme.test',
            'owner_id' => $rep->getKey(),
        ]);

        // 178 characters plus the tag pass the form's limit; rendered, the subject is 226 characters.
        $subject = str_repeat('s', 178).' {{contact.full_name}}';
        $warning = __('email.validation.subject_too_long', ['max' => EmailSendService::SUBJECT_MAX]);

        $page = Livewire::actingAs($rep)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->mountAction('sendEmail')
            ->setActionData(['locale' => 'en', 'subject' => $subject, 'body' => 'Body']);

        $instance = $page->instance();
        assert($instance instanceof ViewContact);

        $preview = $instance->getSchema('mountedActionSchema0')?->getComponent('preview');
        assert($preview instanceof Placeholder);

        $this->assertStringContainsString($warning, (string) $preview->getContent());

        $page
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified($warning);

        $this->assertSame(0, Activity::query()->count());
        $this->assertDatabaseMissing('activity_log', ['description' => ActivityLogEvent::EmailSent->value]);
        Mail::assertNothingQueued();
    }

    #[Test]
    public function the_action_is_disabled_without_an_address_and_hidden_without_the_permission(): void
    {
        $rep = $this->salesRep();
        $readOnly = $this->readOnly();
        $support = $this->support();
        $withoutEmail = Contact::factory()->create(['email' => null, 'owner_id' => $rep->getKey()]);
        $withEmail = Contact::factory()->create(['owner_id' => $rep->getKey()]);
        $lead = Lead::factory()->create(['email' => null, 'owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(ViewContact::class, ['record' => $withoutEmail->getRouteKey()])
            ->assertActionVisible('sendEmail')
            ->assertActionDisabled('sendEmail');

        Livewire::actingAs($rep)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->assertActionDisabled('sendEmail');

        Livewire::actingAs($rep)
            ->test(ListContacts::class)
            ->assertTableActionDisabled('sendEmail', $withoutEmail)
            ->assertTableActionEnabled('sendEmail', $withEmail);

        // Read-only and support users see every record but hold no email.send.
        Livewire::actingAs($readOnly)
            ->test(ViewContact::class, ['record' => $withEmail->getRouteKey()])
            ->assertActionHidden('sendEmail');

        Livewire::actingAs($support)
            ->test(ListContacts::class)
            ->assertTableActionHidden('sendEmail', $withEmail);

        $this->assertFalse($readOnly->can('sendEmail', $withEmail));
        $this->assertFalse($support->can('sendEmail', $withEmail));
        $this->assertTrue($rep->can('sendEmail', $withEmail));
    }

    #[Test]
    public function a_manager_emails_a_team_member_record_from_the_lead_page(): void
    {
        $team = $this->makeTeam();
        $manager = $this->makeUser(CrmRole::SalesManager, ['name' => 'Team Manager', 'email' => 'manager@example.com'], $team);
        $member = $this->salesRep($team);
        $lead = Lead::factory()->create(['first_name' => 'Khalid', 'company_name' => 'Harbi Logistics', 'email' => 'khalid@harbi.test', 'owner_id' => $member->getKey()]);
        $template = EmailTemplate::factory()->forLeads()->create();

        Livewire::actingAs($manager)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->assertActionVisible('sendEmail')
            ->mountAction('sendEmail')
            ->assertActionDataSet(['locale' => 'ar', 'to' => 'khalid@harbi.test'])
            ->setActionData(['template_id' => $template->getKey()])
            ->assertActionDataSet(['subject' => 'متابعة Khalid'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $activity = Activity::query()->latest('id')->firstOrFail();

        $this->assertSame($lead->getKey(), $activity->lead_id);
        $this->assertSame($manager->getKey(), $activity->created_by);
        $this->assertSame(ActivityKind::Email, $activity->kind);
        $this->assertSame('متابعة Khalid', $activity->subject);
        $this->assertStringContainsString('Harbi Logistics', (string) $activity->body);

        Mail::assertQueued(CrmMessage::class, fn (CrmMessage $mail): bool => $mail->hasTo('khalid@harbi.test')
            && $mail->hasReplyTo('manager@example.com')
            && $mail->locale === 'ar');
    }

    #[Test]
    public function the_action_is_available_from_the_contacts_and_leads_tables(): void
    {
        $rep = $this->salesRep();
        $contact = Contact::factory()->create(['first_name' => 'Sara', 'email' => 'sara@acme.test', 'owner_id' => $rep->getKey()]);
        $lead = Lead::factory()->create(['first_name' => 'Khalid', 'email' => 'khalid@harbi.test', 'owner_id' => $rep->getKey()]);

        Livewire::actingAs($rep)
            ->test(ListContacts::class)
            ->assertTableActionVisible('sendEmail', $contact)
            ->callTableAction('sendEmail', $contact, data: ['locale' => 'en', 'subject' => 'Hi {{contact.first_name}}', 'body' => 'Contact body'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('email.notifications.sent', ['to' => 'sara@acme.test']));

        Livewire::actingAs($rep)
            ->test(ListLeads::class)
            ->assertTableActionVisible('sendEmail', $lead)
            ->callTableAction('sendEmail', $lead, data: ['locale' => 'ar', 'subject' => 'Hi {{lead.first_name}}', 'body' => 'Lead body'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('email.notifications.sent', ['to' => 'khalid@harbi.test']));

        $this->assertSame(2, Activity::query()->where('kind', ActivityKind::Email->value)->count());
        $this->assertDatabaseHas('activities', ['contact_id' => $contact->getKey(), 'subject' => 'Hi Sara', 'body' => 'Contact body']);
        $this->assertDatabaseHas('activities', ['lead_id' => $lead->getKey(), 'subject' => 'Hi Khalid', 'body' => 'Lead body']);

        Mail::assertQueued(CrmMessage::class, fn (CrmMessage $mail): bool => $mail->hasTo('sara@acme.test') && $mail->locale === 'en');
        Mail::assertQueued(CrmMessage::class, fn (CrmMessage $mail): bool => $mail->hasTo('khalid@harbi.test') && $mail->locale === 'ar');
        Mail::assertQueuedCount(2);
    }

    #[Test]
    public function the_preview_shows_the_final_text_and_the_mail_warning_follows_the_transport(): void
    {
        $rep = $this->salesRep();
        $contact = Contact::factory()->create(['first_name' => 'Sara', 'owner_id' => $rep->getKey()]);

        config()->set('mail.default', 'log');

        $page = Livewire::actingAs($rep)
            ->test(ViewContact::class, ['record' => $contact->getRouteKey()])
            ->mountAction('sendEmail')
            ->setActionData(['subject' => 'Hello {{contact.first_name}}', 'body' => 'Bye {{contact.nickname}}'])
            ->assertSchemaComponentExists('mail_warning', 'mountedActionSchema0')
            ->assertSchemaComponentExists('preview', 'mountedActionSchema0');

        $instance = $page->instance();
        assert($instance instanceof ViewContact);

        $schema = $instance->getSchema('mountedActionSchema0');
        assert($schema instanceof Schema);

        $preview = $schema->getComponent('preview');
        assert($preview instanceof Placeholder);

        $rendered = (string) $preview->getContent();

        $this->assertStringContainsString('Hello Sara', $rendered);
        $this->assertStringContainsString('Bye', $rendered);
        $this->assertStringNotContainsString('{{', $rendered);
        $this->assertStringContainsString(__('email.helpers.unknown_tags', ['tags' => 'contact.nickname']), $rendered);

        $warning = $schema->getComponent('mail_warning', withHidden: true);
        assert($warning instanceof Placeholder);

        $this->assertTrue($warning->isVisible());
        $this->assertSame(__('email.helpers.mail_not_configured'), (string) $warning->getContent());

        config()->set('mail.default', 'smtp');

        $this->assertFalse($warning->isVisible());
    }
}
