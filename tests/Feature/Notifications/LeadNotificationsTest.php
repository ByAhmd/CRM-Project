<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\CrmRole;
use App\Enums\LeadStatusKind;
use App\Exceptions\Leads\InvalidLeadTransitionException;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\LeadConvertedNotification;
use App\Services\Leads\ConversionRequest;
use App\Services\Leads\LeadConversionWorkflow;
use App\Services\Leads\LeadStatusWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Lead conversion notifications (plan section 3.6, decisions D-7, D-10):
 * the owner hears, in their own language, what someone else's conversion
 * produced — and nothing when they converted the lead themselves.
 */
final class LeadNotificationsTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        Notification::fake();
    }

    #[Test]
    public function a_conversion_by_the_manager_notifies_the_arabic_owner_in_arabic(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $owner = $this->makeUser(CrmRole::SalesRep, ['name' => 'مالك العميل', 'locale' => 'ar'], $team);
        $lead = $this->qualifiedLead($owner, ['first_name' => 'سارة', 'last_name' => 'العتيبي', 'company_name' => 'شركة الأفق']);

        $result = app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest(
            accountMode: ConversionRequest::ACCOUNT_NEW,
            createDeal: true,
            dealTitle: 'Ofoq pilot',
            dealAmount: '5000',
        ), $manager);

        Notification::assertSentToTimes($owner, LeadConvertedNotification::class, 1);
        Notification::assertNotSentTo($manager, LeadConvertedNotification::class);
        Notification::assertSentTo($owner, LeadConvertedNotification::class, function (LeadConvertedNotification $notification, array $channels) use ($owner, $lead, $result): bool {
            $this->assertSame(['database'], $channels);
            $this->assertSame('ar', $notification->locale);

            $database = $notification->toDatabase($owner);
            $body = (string) ($database['body'] ?? '');

            $this->assertSame(__('notifications.events.lead_converted.title', [], 'ar'), $database['title']);
            $this->assertNotSame(__('notifications.events.lead_converted.title', [], 'en'), $database['title']);
            $this->assertStringContainsString('سارة العتيبي', $body);
            $this->assertStringContainsString('Sales Manager', $body);
            $this->assertStringContainsString('شركة الأفق', $body);
            $this->assertStringContainsString($result->contact->full_name, $body);
            $this->assertStringContainsString('Ofoq pilot', $body);
            $this->assertStringContainsString(LeadResource::getUrl('view', ['record' => $lead]), (string) json_encode($database, JSON_UNESCAPED_SLASHES));

            $mail = $notification->toMail($owner);

            $this->assertSame(__('notifications.events.lead_converted.title', [], 'ar'), $mail->subject);
            $this->assertSame(__('notifications.common.greeting', ['name' => 'مالك العميل'], 'ar'), $mail->greeting);
            $this->assertSame(LeadResource::getUrl('view', ['record' => $lead]), $mail->actionUrl);

            return true;
        });
    }

    #[Test]
    public function an_english_owner_is_notified_in_english_and_only_about_what_was_produced(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $owner = $this->makeUser(CrmRole::SalesRep, ['name' => 'English Owner', 'locale' => 'en'], $team);
        $lead = $this->qualifiedLead($owner, ['first_name' => 'Nora', 'last_name' => 'Hassan']);

        app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest(
            accountMode: ConversionRequest::ACCOUNT_NONE,
            createDeal: false,
        ), $manager);

        Notification::assertSentTo($owner, LeadConvertedNotification::class, function (LeadConvertedNotification $notification) use ($owner): bool {
            $this->assertSame('en', $notification->locale);

            $database = $notification->toDatabase($owner);
            $body = (string) ($database['body'] ?? '');

            $this->assertSame(__('notifications.events.lead_converted.title', [], 'en'), $database['title'] ?? null);
            $this->assertStringContainsString('Nora Hassan', $body);
            $this->assertStringContainsString(__('notifications.events.lead_converted.contact', ['contact' => 'Nora Hassan'], 'en'), $body);
            $this->assertStringNotContainsString(__('notifications.events.lead_converted.account', ['account' => ''], 'en'), $body);
            $this->assertStringNotContainsString(__('notifications.events.lead_converted.deal', ['deal' => ''], 'en'), $body);

            $lines = array_map(static fn (mixed $line): string => (string) $line, $notification->toMail($owner)->introLines);

            $this->assertCount(2, $lines);

            return true;
        });
    }

    #[Test]
    public function the_owner_converting_their_own_lead_is_not_notified(): void
    {
        $owner = $this->salesRep();
        $lead = $this->qualifiedLead($owner);

        app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest(accountMode: ConversionRequest::ACCOUNT_NEW), $owner);

        Notification::assertNothingSent();
    }

    #[Test]
    public function an_unowned_lead_converted_by_an_admin_notifies_nobody(): void
    {
        $admin = $this->admin();
        $lead = Lead::factory()->create(['owner_id' => null]);
        app(LeadStatusWorkflow::class)->transition($lead, $this->statusOfKind(LeadStatusKind::Qualified), $admin, 'Budget confirmed.');

        app(LeadConversionWorkflow::class)->convert($lead->refresh(), new ConversionRequest(accountMode: ConversionRequest::ACCOUNT_NEW), $admin);

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_refused_conversion_sends_nothing(): void
    {
        $manager = $this->salesManager();
        $owner = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $owner->getKey()]);

        try {
            app(LeadConversionWorkflow::class)->convert($lead, new ConversionRequest(accountMode: ConversionRequest::ACCOUNT_NEW), $manager);
            $this->fail('An unqualified lead was converted.');
        } catch (InvalidLeadTransitionException) {
            Notification::assertNothingSent();
        }
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
}
