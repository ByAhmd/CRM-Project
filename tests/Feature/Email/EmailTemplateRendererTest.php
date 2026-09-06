<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\CrmRole;
use App\Mail\CrmMessage;
use App\Models\Account;
use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Services\Email\EmailTemplateRenderer;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Rendering a template against one recipient (decision D-10): tags are
 * replaced whatever the spacing, unknown tags are blanked and reported, the
 * subject stays on one line, and the mail view — not the renderer — escapes
 * the values so a hostile name never becomes markup in the message.
 */
final class EmailTemplateRendererTest extends TestCase
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
    public function tags_are_replaced_with_and_without_inner_spaces(): void
    {
        $rep = $this->makeUser(CrmRole::SalesRep, ['name' => 'Nora Rep']);
        $account = Account::factory()->create(['name' => 'Acme Trading', 'owner_id' => $rep->getKey()]);
        $contact = Contact::factory()->create(['first_name' => 'Sara', 'last_name' => 'Otaibi', 'account_id' => $account->getKey(), 'owner_id' => $rep->getKey()]);

        $rendered = $this->renderer()->render(
            'Hello {{contact.first_name}} from {{ organisation.name }}',
            "Dear {{ contact.full_name }},\n\n{{account.name}} — {{  user.name  }}\n{{contact.first_name}} again",
            $contact,
            $rep,
        );

        $organisation = app(SettingsRepository::class)->organisationName();

        $this->assertSame("Hello Sara from {$organisation}", $rendered->subject);
        $this->assertSame("Dear Sara Otaibi,\n\nAcme Trading — Nora Rep\nSara again", $rendered->body);
        $this->assertSame([], $rendered->unknownTags);
        $this->assertFalse($rendered->hasUnknownTags());
    }

    #[Test]
    public function unknown_tags_are_blanked_and_reported_once_each(): void
    {
        $rep = $this->salesRep();
        $contact = Contact::factory()->create(['first_name' => 'Sara', 'owner_id' => $rep->getKey()]);

        $rendered = $this->renderer()->render(
            'Re: {{contact.nickname}}',
            'Hi {{contact.first_name}}, {{ deal.title }} and {{contact.nickname}} again; {{not a tag}} stays.',
            $contact,
            $rep,
        );

        $this->assertSame('Re:', $rendered->subject);
        $this->assertSame('Hi Sara,  and  again; {{not a tag}} stays.', $rendered->body);
        $this->assertSame(['contact.nickname', 'deal.title'], $rendered->unknownTags);
        $this->assertTrue($rendered->hasUnknownTags());
    }

    #[Test]
    public function the_subject_is_collapsed_to_a_single_line(): void
    {
        $rep = $this->salesRep();
        $contact = Contact::factory()->create(['first_name' => 'Sara', 'owner_id' => $rep->getKey()]);

        $rendered = $this->renderer()->render("  Hello\r\n{{contact.first_name}}\n\n  how   are\tyou ", "Line one\n\nLine three", $contact, $rep);

        $this->assertSame('Hello Sara how are you', $rendered->subject);
        $this->assertSame("Line one\n\nLine three", $rendered->body);
        $this->assertSame('Hello world', EmailTemplateRenderer::singleLine("Hello\nworld"));
    }

    #[Test]
    public function values_are_kept_raw_by_the_renderer_and_escaped_by_the_mail_view(): void
    {
        $rep = $this->makeUser(CrmRole::SalesRep, ['name' => '*Nora* _Rep_', 'email' => 'nora@example.com']);
        $contact = Contact::factory()->create([
            'first_name' => '<b>Sara</b>',
            'last_name' => 'O\'Neil & Sons',
            'owner_id' => $rep->getKey(),
        ]);

        $rendered = $this->renderer()->render('Hello {{contact.first_name}}', "Dear {{contact.full_name}},\n- not a list\n# not a heading\n*emphasis* stays literal", $contact, $rep);

        $this->assertSame('Hello <b>Sara</b>', $rendered->subject);
        $this->assertStringContainsString("<b>Sara</b> O'Neil & Sons", $rendered->body);

        $message = new CrmMessage(
            messageSubject: $rendered->subject,
            body: $rendered->body,
            recipientName: '*<b>Sara</b> & Sons*',
            organisationName: '_ZonKSA_',
            replyToAddress: $rep->email,
            replyToName: $rep->name,
            locale: 'en',
        );

        $html = $message->render();

        $this->assertStringContainsString('&lt;b&gt;Sara&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Sara</b>', $html);
        $this->assertStringContainsString('&amp; Sons', $html);
        // Laravel's markdown pipeline normalises nl2br's <br /> to <br>.
        $this->assertStringContainsString('- not a list<br>', $html);
        $this->assertStringContainsString('# not a heading', $html);
        $this->assertStringContainsString('*emphasis* stays literal', $html);
        $this->assertStringNotContainsString('<li>', $html);
        $this->assertStringNotContainsString('<h1>', $html);
        $this->assertStringNotContainsString('<em>', $html);
        $this->assertStringNotContainsString('<strong>', $html);
        // Names and the organisation are literal text in the greeting, the reply hint and the footer.
        $this->assertStringContainsString(e(__('email.mail.greeting', ['name' => '*<b>Sara</b> & Sons*'], 'en')), $html);
        $this->assertStringContainsString(e(__('email.mail.reply_hint', ['name' => '*Nora* _Rep_'], 'en')), $html);
        $this->assertStringContainsString('_ZonKSA_', $html);
        // The body block carries the paragraph typography the theme gives real paragraphs, in the same block that holds the message.
        $this->assertMatchesRegularExpression('/<div[^>]*style="(?=[^"]*font-size: 16px)(?=[^"]*line-height: 1\.5em)[^"]*"[^>]*>Dear &lt;b&gt;Sara/', $html);
    }

    #[Test]
    public function the_mail_is_rendered_in_the_locale_it_was_given(): void
    {
        $rep = $this->makeUser(CrmRole::SalesRep, ['name' => 'Nora Rep', 'email' => 'nora@example.com']);
        $contact = Contact::factory()->create(['first_name' => 'Sara', 'last_name' => 'Otaibi', 'owner_id' => $rep->getKey()]);
        $template = EmailTemplate::factory()->create();

        foreach (['ar', 'en'] as $locale) {
            $rendered = $this->renderer()->render($template->subjectFor($locale), $template->bodyFor($locale), $contact, $rep);

            $message = new CrmMessage(
                messageSubject: $rendered->subject,
                body: $rendered->body,
                recipientName: $contact->full_name,
                organisationName: 'ZonKSA',
                replyToAddress: $rep->email,
                replyToName: $rep->name,
                locale: $locale,
            );

            $html = $message->render();

            $this->assertSame($locale, $message->locale);
            $this->assertStringContainsString(e(__('email.mail.greeting', ['name' => 'Sara Otaibi'], $locale)), $html);
            $this->assertStringContainsString(e(__('email.mail.footer', ['organisation' => 'ZonKSA'], $locale)), $html);
        }

        $this->assertSame('ar', app()->getLocale());
    }

    private function renderer(): EmailTemplateRenderer
    {
        return app(EmailTemplateRenderer::class);
    }
}
