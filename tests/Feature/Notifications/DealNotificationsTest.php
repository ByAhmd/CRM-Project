<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\CloseReasonKind;
use App\Enums\CrmRole;
use App\Enums\StageKind;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\Team;
use App\Notifications\DealClosedNotification;
use App\Notifications\DealStageChangedNotification;
use App\Services\Deals\DealCloseService;
use App\Services\Deals\DealStageWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Deal notifications (plan section 3.6, decision D-10): the owner hears
 * about a stage change made by someone else, the owner and the team manager
 * about a win or a loss, nobody about their own action and nobody twice.
 */
final class DealNotificationsTest extends TestCase
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
    public function a_stage_change_by_the_manager_notifies_the_owner_with_both_stage_names_and_the_open_action(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->makeUser(CrmRole::SalesRep, ['name' => 'Owner Rep', 'locale' => 'en'], $team);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'title' => 'Fleet renewal']);
        $from = $deal->stage;
        $to = app(DealStageWorkflow::class)->allowedStages($deal)->firstOrFail();

        app(DealStageWorkflow::class)->transition($deal, $to, $manager, 'Demo done');

        Notification::assertSentToTimes($rep, DealStageChangedNotification::class, 1);
        Notification::assertNotSentTo($manager, DealStageChangedNotification::class);
        Notification::assertSentTo($rep, DealStageChangedNotification::class, function (DealStageChangedNotification $notification, array $channels) use ($rep, $deal, $from, $to): bool {
            $this->assertSame(['database'], $channels);
            $this->assertSame('en', $notification->locale);

            $body = $notification->body();

            $this->assertStringContainsString('Fleet renewal', $body);
            $this->assertStringContainsString((string) $from?->display_name, $body);
            $this->assertStringContainsString($to->display_name, $body);
            $this->assertStringContainsString('Sales Manager', $body);

            $database = $notification->toDatabase($rep);
            $url = DealResource::getUrl('view', ['record' => $deal]);

            // Rendered in the recipient's locale, whatever the app locale is at send time.
            $this->assertSame(__('notifications.events.deal_stage_changed.title', [], 'en'), $database['title'] ?? null);
            $this->assertSame(__('notifications.events.deal_stage_changed.title', [], 'en'), $notification->toMail($rep)->subject);
            $this->assertStringContainsString($url, (string) json_encode($database, JSON_UNESCAPED_SLASHES));
            $this->assertSame($url, $notification->toMail($rep)->actionUrl);

            return true;
        });
    }

    #[Test]
    public function the_owner_moving_their_own_deal_is_not_notified(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $to = app(DealStageWorkflow::class)->allowedStages($deal)->firstOrFail();

        app(DealStageWorkflow::class)->transition($deal, $to, $rep);

        Notification::assertNothingSent();
    }

    #[Test]
    public function an_unowned_deal_moved_by_an_admin_notifies_nobody(): void
    {
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => null]);
        $to = app(DealStageWorkflow::class)->allowedStages($deal)->firstOrFail();

        app(DealStageWorkflow::class)->transition($deal, $to, $admin);

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_win_by_an_admin_notifies_the_owner_and_the_team_manager_once_each(): void
    {
        $manager = $this->salesManager();
        $team = $this->makeTeam(manager: $manager);
        $manager->forceFill(['team_id' => $team->getKey()])->save();
        $rep = $this->salesRep($team);
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'title' => 'Data centre']);
        $reason = $this->reasonOfKind(CloseReasonKind::Won);

        app(DealCloseService::class)->win($deal, $reason, $admin, 'Signed');

        Notification::assertSentToTimes($rep, DealClosedNotification::class, 1);
        Notification::assertSentToTimes($manager, DealClosedNotification::class, 1);
        Notification::assertNotSentTo($admin, DealClosedNotification::class);
        Notification::assertNotSentTo($rep, DealStageChangedNotification::class);

        Notification::assertSentTo($manager, DealClosedNotification::class, function (DealClosedNotification $notification) use ($manager, $reason): bool {
            $this->assertTrue($notification->isWon());
            $this->assertSame($manager->preferredLocale(), $notification->locale);
            $this->assertSame(__('notifications.events.deal_closed.title_won'), $notification->title());
            $this->assertStringContainsString('Data centre', $notification->body());
            $this->assertStringContainsString($reason->display_name, $notification->body());
            $this->assertStringContainsString('Admin User', $notification->body());

            return true;
        });
    }

    #[Test]
    public function the_actor_is_never_told_about_their_own_close(): void
    {
        $manager = $this->salesManager();
        $team = $this->makeTeam(manager: $manager);
        $manager->forceFill(['team_id' => $team->getKey()])->save();
        $rep = $this->salesRep($team);
        $reason = $this->reasonOfKind(CloseReasonKind::Won);

        // The manager closes: only the owner hears.
        $byManager = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        app(DealCloseService::class)->win($byManager, $reason, $manager);

        Notification::assertSentToTimes($rep, DealClosedNotification::class, 1);
        Notification::assertNotSentTo($manager, DealClosedNotification::class);

        // The owner closes: only the manager hears.
        $byOwner = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        app(DealCloseService::class)->win($byOwner, $reason, $rep);

        Notification::assertSentToTimes($rep, DealClosedNotification::class, 1);
        Notification::assertSentToTimes($manager, DealClosedNotification::class, 1);
    }

    #[Test]
    public function a_manager_who_owns_the_deal_is_notified_once_and_a_team_without_a_manager_adds_nobody(): void
    {
        $manager = $this->salesManager();
        $team = $this->makeTeam(manager: $manager);
        $manager->forceFill(['team_id' => $team->getKey()])->save();
        $admin = $this->admin();
        $reason = $this->reasonOfKind(CloseReasonKind::Lost);

        $ownedByManager = Deal::factory()->create(['owner_id' => $manager->getKey()]);
        app(DealCloseService::class)->lose($ownedByManager, $reason, $admin, 'Went with a competitor');

        Notification::assertSentToTimes($manager, DealClosedNotification::class, 1);

        $leaderless = Team::factory()->create(['name_en' => 'Jeddah', 'name_ar' => 'جدة', 'manager_user_id' => null]);
        $rep = $this->salesRep($leaderless);
        $onLeaderlessTeam = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        app(DealCloseService::class)->lose($onLeaderlessTeam, $reason, $admin);

        Notification::assertSentToTimes($rep, DealClosedNotification::class, 1);
        Notification::assertSentToTimes($manager, DealClosedNotification::class, 1);
        Notification::assertNotSentTo($admin, DealClosedNotification::class);
    }

    #[Test]
    public function a_loss_carries_the_lost_wording_and_the_reason(): void
    {
        $manager = $this->salesManager();
        $rep = $this->makeUser(CrmRole::SalesRep, ['name' => 'Arabic Rep', 'locale' => 'ar']);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'title' => 'Warehouse racking']);
        $reason = $this->reasonOfKind(CloseReasonKind::Lost);

        app(DealCloseService::class)->lose($deal, $reason, $manager, 'Budget cut');

        Notification::assertSentTo($rep, DealClosedNotification::class, function (DealClosedNotification $notification) use ($rep, $reason): bool {
            $this->assertFalse($notification->isWon());
            $this->assertSame('ar', $notification->locale);

            $database = $notification->toDatabase($rep);

            $this->assertSame(__('notifications.events.deal_closed.title_lost', [], 'ar'), $database['title'] ?? null);
            $this->assertStringContainsString($reason->display_name, (string) ($database['body'] ?? ''));
            $this->assertStringContainsString('Warehouse racking', (string) ($database['body'] ?? ''));

            $mail = $notification->toMail($rep);

            $this->assertSame(__('notifications.events.deal_closed.title_lost', [], 'ar'), $mail->subject);
            $this->assertSame(__('notifications.common.greeting', ['name' => 'Arabic Rep'], 'ar'), $mail->greeting);

            return true;
        });
    }

    #[Test]
    public function reopening_notifies_the_owner_of_a_move_into_the_default_stage(): void
    {
        $manager = $this->salesManager();
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $default = $deal->stage;
        $this->assertInstanceOf(PipelineStage::class, $default);

        app(DealCloseService::class)->win($deal, $this->reasonOfKind(CloseReasonKind::Won), $rep);

        Notification::assertNothingSent();

        $wonStage = $deal->refresh()->stage;
        $this->assertInstanceOf(PipelineStage::class, $wonStage);
        $this->assertSame(StageKind::Won, $wonStage->kind);

        app(DealCloseService::class)->reopen($deal, $manager, 'Customer came back');

        Notification::assertNotSentTo($rep, DealClosedNotification::class);
        Notification::assertSentToTimes($rep, DealStageChangedNotification::class, 1);
        Notification::assertSentTo($rep, DealStageChangedNotification::class, function (DealStageChangedNotification $notification) use ($default, $wonStage): bool {
            $this->assertStringContainsString($wonStage->display_name, $notification->body());
            $this->assertStringContainsString($default->display_name, $notification->body());

            return true;
        });
    }

    #[Test]
    public function the_owners_preference_decides_the_channels_of_a_deal_notification(): void
    {
        config()->set('mail.default', 'smtp');

        $manager = $this->salesManager();
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $to = app(DealStageWorkflow::class)->allowedStages($deal)->firstOrFail();

        $rep->notificationPreferences()->create(['event' => 'deal_stage_changed', 'database' => true, 'mail' => true]);

        app(DealStageWorkflow::class)->transition($deal, $to, $manager);

        Notification::assertSentTo($rep, DealStageChangedNotification::class, fn (DealStageChangedNotification $notification, array $channels): bool => $channels === ['database', 'mail']);
    }
}
