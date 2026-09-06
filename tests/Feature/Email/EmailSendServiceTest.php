<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\ActivityDirection;
use App\Enums\ActivityKind;
use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Exceptions\Email\EmailNotSendableException;
use App\Mail\CrmMessage;
use App\Models\Account;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Services\Email\EmailSendService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Sending a templated email (decision D-10): an outbound email activity and
 * an audit row are written in one transaction, the message is queued to the
 * recipient with the sender as reply-to in the chosen language, and the
 * send is refused without an address, without the permission or outside
 * the actor's reach.
 */
final class EmailSendServiceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        Mail::fake();
    }

    #[Test]
    public function a_sent_email_is_recorded_as_an_outbound_activity_audited_and_queued(): void
    {
        $rep = $this->makeUser(CrmRole::SalesRep, ['name' => 'Nora Rep', 'email' => 'nora@example.com']);
        $account = Account::factory()->create(['name' => 'Acme Trading', 'owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create([
            'first_name' => 'Sara',
            'last_name' => 'Otaibi',
            'email' => 'sara@acme.test',
            'preferred_locale' => 'ar',
            'account_id' => $account->getKey(),
            'owner_id' => $rep->getKey(),
        ]);
        $template = EmailTemplate::factory()->create(['name_en' => 'Thanks', 'name_ar' => 'شكراً']);

        $activity = $this->service()->send($contact, $rep, 'Hello {{contact.first_name}}', "Dear {{contact.full_name}},\nfrom {{account.name}}", 'en', $template);

        $this->assertInstanceOf(Activity::class, $activity);
        $this->assertSame(ActivityKind::Email, $activity->kind);
        $this->assertSame(ActivityDirection::Outbound, $activity->direction);
        $this->assertSame('Hello Sara', $activity->subject);
        $this->assertSame("Dear Sara Otaibi,\nfrom Acme Trading", $activity->body);
        $this->assertSame($contact->getKey(), $activity->contact_id);
        $this->assertSame($account->getKey(), $activity->account_id);
        $this->assertSame($rep->getKey(), $activity->owner_id);
        $this->assertSame($rep->getKey(), $activity->created_by);
        $this->assertTrue($activity->type?->is_system);
        $this->assertSame([
            'template_id' => $template->getKey(),
            'template_name' => $template->display_name,
            'to' => 'sara@acme.test',
            'locale' => 'en',
            'sent_by' => $rep->getKey(),
        ], $activity->payload);

        $log = ActivityLog::query()->where('description', ActivityLogEvent::EmailSent->value)->latest('id')->firstOrFail();
        $this->assertSame(Contact::class, $log->subject_type);
        $this->assertSame($contact->getKey(), (int) $log->subject_id);
        $this->assertSame($rep->getKey(), (int) $log->causer_id);
        $this->assertSame('Hello Sara', $log->properties->get('subject_label'));
        $this->assertSame('sara@acme.test', $log->properties->get('to'));
        $this->assertSame($template->display_name, $log->properties->get('template'));
        $this->assertSame('en', $log->properties->get('locale'));
        $this->assertSame(ActivityLogEvent::EmailSent->logName(), $log->log_name);

        Mail::assertQueued(CrmMessage::class, function (CrmMessage $mail) use ($rep): bool {
            return $mail->hasTo('sara@acme.test', 'Sara Otaibi')
                && $mail->hasReplyTo('nora@example.com', 'Nora Rep')
                && $mail->locale === 'en'
                && $mail->messageSubject === 'Hello Sara'
                && $mail->body === "Dear Sara Otaibi,\nfrom Acme Trading"
                && $mail->replyToName === $rep->name
                && $mail->envelope()->subject === 'Hello Sara';
        });
        Mail::assertQueuedCount(1);
    }

    #[Test]
    public function the_message_is_rendered_by_the_real_mailer_in_the_recipient_locale(): void
    {
        Mail::clearResolvedInstances();
        $this->app->forgetInstance('mailer');
        $this->app->forgetInstance('mail.manager');

        $rep = $this->makeUser(CrmRole::SalesRep, ['name' => 'Nora Rep', 'email' => 'nora@example.com']);
        $contact = Contact::factory()->create(['first_name' => 'Sara', 'last_name' => 'Otaibi', 'email' => 'sara@acme.test', 'owner_id' => $rep->getKey()]);

        $this->service()->send($contact, $rep, 'مرحباً {{contact.first_name}}', 'نص الرسالة', 'ar');

        $transport = Mail::mailer('array')->getSymfonyTransport();
        assert($transport instanceof ArrayTransport);

        $messages = $transport->messages();

        $this->assertCount(1, $messages);

        $delivered = $messages->first();
        assert($delivered instanceof SentMessage);

        $sent = $delivered->getOriginalMessage();
        assert($sent instanceof Email);

        $html = (string) $sent->getHtmlBody();

        $this->assertSame('مرحباً Sara', $sent->getSubject());
        $this->assertSame('sara@acme.test', $sent->getTo()[0]->getAddress());
        $this->assertSame('nora@example.com', $sent->getReplyTo()[0]->getAddress());
        $this->assertSame((string) config('mail.from.address'), $sent->getFrom()[0]->getAddress());
        $this->assertStringContainsString(e(__('email.mail.greeting', ['name' => 'Sara Otaibi'], 'ar')), $html);
        $this->assertStringContainsString('نص الرسالة', $html);
    }

    #[Test]
    public function a_lead_can_be_emailed_without_a_template(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['first_name' => 'Khalid', 'email' => 'khalid@harbi.test', 'owner_id' => $rep->getKey()]);

        $activity = $this->service()->send($lead, $rep, 'Hi {{lead.first_name}}', 'Body', 'ar');

        $this->assertSame($lead->getKey(), $activity->lead_id);
        $this->assertNull($activity->contact_id);
        $this->assertSame('Hi Khalid', $activity->subject);
        $this->assertNull($activity->payload['template_id'] ?? null);
        $this->assertNull($activity->payload['template_name'] ?? null);
        $this->assertSame('ar', $activity->payload['locale'] ?? null);
        $this->assertNotNull($lead->refresh()->last_activity_at);

        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::EmailSent->value,
            'subject_type' => Lead::class,
            'subject_id' => $lead->getKey(),
        ]);

        Mail::assertQueued(CrmMessage::class, fn (CrmMessage $mail): bool => $mail->hasTo('khalid@harbi.test') && $mail->locale === 'ar');
    }

    #[Test]
    public function the_default_locale_is_the_contact_preference_or_the_app_locale_and_an_unknown_one_falls_back(): void
    {
        $rep = $this->salesRep();
        $contact = Contact::factory()->create(['preferred_locale' => 'en', 'owner_id' => $rep->getKey()]);
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->assertSame('en', EmailSendService::defaultLocaleFor($contact));
        $this->assertSame('ar', EmailSendService::defaultLocaleFor($lead));

        $activity = $this->service()->send($contact, $rep, 'Subject', 'Body', 'fr');

        $this->assertSame('en', $activity->payload['locale'] ?? null);
        Mail::assertQueued(CrmMessage::class, fn (CrmMessage $mail): bool => $mail->locale === 'en');
    }

    #[Test]
    public function a_recipient_without_an_address_is_refused_before_anything_is_written(): void
    {
        $rep = $this->salesRep();
        $contact = Contact::factory()->create(['email' => null, 'owner_id' => $rep->getKey()]);

        try {
            $this->service()->send($contact, $rep, 'Subject', 'Body', 'ar');
            $this->fail('The email was sent without an address.');
        } catch (EmailNotSendableException $exception) {
            $this->assertSame(__('email.validation.no_email'), $exception->getMessage());
        }

        $this->assertSame(0, Activity::query()->count());
        $this->assertDatabaseMissing('activity_log', ['description' => ActivityLogEvent::EmailSent->value]);
        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_subject_that_outgrows_the_activity_width_once_rendered_is_refused_before_anything_is_written(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create([
            'company_name' => str_repeat('C', 150),
            'email' => 'khalid@harbi.test',
            'owner_id' => $rep->getKey(),
        ]);

        // 178 characters plus the tag fit the form's limit; rendered, the subject is 329 characters.
        $subject = str_repeat('s', 178).' {{lead.company_name}}';

        $this->assertSame(EmailSendService::SUBJECT_MAX, mb_strlen($subject));
        $this->assertFalse(EmailSendService::subjectIsTooLong($subject));
        $this->assertTrue(EmailSendService::subjectIsTooLong(str_repeat('s', EmailSendService::SUBJECT_MAX + 1)));

        try {
            $this->service()->send($lead, $rep, $subject, 'Body', 'en');
            $this->fail('An overlong rendered subject was sent.');
        } catch (EmailNotSendableException $exception) {
            $this->assertSame(__('email.validation.subject_too_long', ['max' => EmailSendService::SUBJECT_MAX]), $exception->getMessage());
        }

        $this->assertSame(0, Activity::query()->count());
        $this->assertDatabaseMissing('activity_log', ['description' => ActivityLogEvent::EmailSent->value]);
        Mail::assertNothingQueued();

        // Exactly the limit still goes through.
        $activity = $this->service()->send($lead, $rep, str_repeat('s', 49).' {{lead.company_name}}', 'Body', 'en');

        $this->assertSame(EmailSendService::SUBJECT_MAX, mb_strlen($activity->subject));
    }

    #[Test]
    public function an_actor_without_the_permission_is_refused(): void
    {
        $readOnly = $this->readOnly();
        $contact = Contact::factory()->create(['owner_id' => $this->salesRep()->getKey()]);

        $this->assertFalse($readOnly->can('sendEmail', $contact));

        try {
            $this->service()->send($contact, $readOnly, 'Subject', 'Body', 'ar');
            $this->fail('A read-only user sent an email.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Activity::query()->count());
        }

        Mail::assertNothingQueued();
    }

    #[Test]
    public function an_actor_outside_the_record_reach_is_refused_while_a_manager_of_the_team_is_not(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $member = $this->salesRep($team);
        $outsider = $this->salesRep();
        $contact = Contact::factory()->create(['owner_id' => $member->getKey()]);
        $lead = Lead::factory()->create(['owner_id' => $member->getKey()]);

        $this->assertFalse($outsider->can('sendEmail', $contact));
        $this->assertFalse($outsider->can('sendEmail', $lead));
        $this->assertTrue($member->can('sendEmail', $contact));
        $this->assertTrue($manager->can('sendEmail', $contact));
        $this->assertTrue($manager->can('sendEmail', $lead));

        $this->expectException(AuthorizationException::class);
        $this->service()->send($contact, $outsider, 'Subject', 'Body', 'ar');
    }

    #[Test]
    public function only_contacts_and_leads_can_be_emailed(): void
    {
        $rep = $this->salesRep();
        $account = Account::factory()->create(['email' => 'info@acme.test', 'owner_id' => $rep->getKey()]);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->send($account, $rep, 'Subject', 'Body', 'ar');
    }

    #[Test]
    public function under_the_log_mailer_the_activity_is_still_recorded_and_the_message_still_queued(): void
    {
        config()->set('mail.default', 'log');

        $rep = $this->salesRep();
        $contact = Contact::factory()->create(['email' => 'sara@acme.test', 'owner_id' => $rep->getKey()]);

        $activity = $this->service()->send($contact, $rep, 'Subject', 'Body', 'ar');

        $this->assertSame(ActivityKind::Email, $activity->kind);
        $this->assertDatabaseHas('activities', ['id' => $activity->getKey(), 'direction' => ActivityDirection::Outbound->value]);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::EmailSent->value, 'subject_id' => $contact->getKey()]);
        Mail::assertQueued(CrmMessage::class, fn (CrmMessage $mail): bool => $mail->hasTo('sara@acme.test'));
    }

    private function service(): EmailSendService
    {
        return app(EmailSendService::class);
    }
}
