<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Localisation;

use App\Enums\CrmRole;
use App\Mail\CrmMessage;
use App\Notifications\UserInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Outgoing mail in Arabic (D-5, D-10): the recipient's locale must govern
 * every visible line and the reading direction of the message.
 *
 * Laravel's notification and mail layouts carry their own strings through
 * JSON keys (__('Regards,'), __('All rights reserved.'), the action-button
 * subcopy). The project forbids lang/*.json and never publishes the mail
 * views, so those lines stay English inside an Arabic message, and the
 * layout's <html> has no dir attribute, so the body is laid out LTR.
 */
final class MailLocalisationTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function an_arabic_notification_mail_has_no_english_framework_lines_and_reads_rtl(): void
    {
        $this->seedAccess();
        $this->usePanel();

        $invitee = $this->makeUser(CrmRole::SalesRep, ['name' => 'مندوب', 'locale' => 'ar']);
        app()->setLocale('ar');

        $html = (string) (new UserInvitationNotification('token', 'مدير'))->toMail($invitee)->render();

        $this->assertStringContainsString('مرحباً', $html, 'sanity: the Arabic body rendered');
        $this->assertStringNotContainsString('Regards,', $html);
        $this->assertStringNotContainsString('having trouble clicking', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
        $this->assertMatchesRegularExpression('/<(html|body|table)[^>]*\bdir="rtl"/', $html, 'the Arabic mail layout declares no RTL direction');
    }

    #[Test]
    public function an_arabic_templated_message_has_no_english_footer_and_reads_rtl(): void
    {
        $html = (new CrmMessage(
            messageSubject: 'عرض سعر',
            body: 'نص الرسالة',
            recipientName: 'سارة',
            organisationName: 'شركة الأفق',
            replyToAddress: 'rep@example.com',
            replyToName: 'مندوب',
            locale: 'ar',
        ))->render();

        $this->assertStringContainsString('نص الرسالة', $html, 'sanity: the Arabic body rendered');
        $this->assertStringNotContainsString('All rights reserved', $html);
        $this->assertMatchesRegularExpression('/<(html|body|table)[^>]*\bdir="rtl"/', $html, 'the Arabic mail layout declares no RTL direction');
    }
}
