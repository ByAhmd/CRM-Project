<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\CrmRole;
use App\Enums\LeadStatusKind;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\ActivityType;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\User;
use App\Notifications\LeadStaleNotification;
use App\Services\Activities\ActivityRecorder;
use App\Services\Activities\ActivitySubject;
use App\Services\Leads\LeadStaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The stale-lead pass (plan section 3.6, decision D-1): an open, owned lead
 * idle for crm.leads.stale_days is reported to its owner once, a new
 * activity re-arms it, and converted, terminal or unowned leads are never
 * reported.
 */
final class StaleLeadsTest extends TestCase
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
        config()->set('crm.leads.stale_days', 14);
    }

    #[Test]
    public function an_idle_lead_is_reported_once_and_a_second_run_sends_nothing(): void
    {
        $rep = $this->makeUser(CrmRole::SalesRep, ['name' => 'Idle Owner', 'locale' => 'en']);

        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $idle = Lead::factory()->create(['owner_id' => $rep->getKey(), 'first_name' => 'Faisal', 'last_name' => 'Nasser']);
        $recentlyActive = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->travelTo(Carbon::parse('2026-09-13 09:00:00'));
        $recentlyActive->forceFill(['last_activity_at' => now()])->saveQuietly();
        $fresh = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->travelTo(Carbon::parse('2026-09-16 09:00:00'));

        $this->assertSame(1, $this->service()->notify(now()));

        Notification::assertSentToTimes($rep, LeadStaleNotification::class, 1);
        Notification::assertSentTo($rep, LeadStaleNotification::class, function (LeadStaleNotification $notification, array $channels) use ($rep, $idle): bool {
            $this->assertSame(['database'], $channels);
            $this->assertSame('en', $notification->locale);
            $this->assertSame(15, $notification->daysIdle());

            $database = $notification->toDatabase($rep);
            $encoded = (string) json_encode($database, JSON_UNESCAPED_SLASHES);

            $this->assertSame(__('notifications.events.lead_stale.title', [], 'en'), $database['title'] ?? null);
            $this->assertStringContainsString('Faisal Nasser', $encoded);
            $this->assertStringContainsString('15', (string) ($database['body'] ?? ''));
            $this->assertStringContainsString(LeadResource::getUrl('view', ['record' => $idle]), $encoded);
            $this->assertSame(LeadResource::getUrl('view', ['record' => $idle]), $notification->toMail($rep)->actionUrl);

            return true;
        });

        $this->assertSame('2026-09-16 09:00:00', $idle->refresh()->stale_notified_at?->format('Y-m-d H:i:s'));
        $this->assertNull($recentlyActive->refresh()->stale_notified_at);
        $this->assertNull($fresh->refresh()->stale_notified_at);

        $this->assertSame(0, $this->service()->notify(now()));

        $this->travelTo(Carbon::parse('2026-10-30 09:00:00'));

        // Still stamped: the lead is not reported again until something happens on it.
        $this->assertSame(2, $this->service()->notify(now()));
        Notification::assertSentToTimes($rep, LeadStaleNotification::class, 3);
        $stamp = Lead::query()->whereKey($idle->getKey())->value('stale_notified_at');
        $this->assertInstanceOf(Carbon::class, $stamp);
        $this->assertSame('2026-09-16 09:00:00', $stamp->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function logging_an_activity_re_arms_the_lead_for_a_later_notice(): void
    {
        $rep = $this->salesRep();

        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->travelTo(Carbon::parse('2026-09-16 09:00:00'));
        $this->assertSame(1, $this->service()->notify(now()));
        $this->assertNotNull($lead->refresh()->stale_notified_at);

        $this->travelTo(Carbon::parse('2026-09-17 09:00:00'));
        $type = ActivityType::query()->where('is_active', true)->orderBy('sort')->firstOrFail();
        app(ActivityRecorder::class)->record(ActivitySubject::for($lead), $type, $rep, 'Called back');

        $lead->refresh();
        $this->assertNull($lead->stale_notified_at);
        $this->assertSame('2026-09-17 09:00:00', $lead->last_activity_at?->format('Y-m-d H:i:s'));

        // Not stale any more: idle since the activity, not since creation.
        $this->assertSame(0, $this->service()->notify(now()));

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $this->assertSame(1, $this->service()->notify(now()));

        Notification::assertSentToTimes($rep, LeadStaleNotification::class, 2);
        Notification::assertSentTo($rep, LeadStaleNotification::class, fn (LeadStaleNotification $notification): bool => $notification->daysIdle() === 15);
    }

    #[Test]
    public function a_backdated_activity_does_not_re_arm_a_lead_whose_stamp_is_newer(): void
    {
        $rep = $this->salesRep();

        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->travelTo(Carbon::parse('2026-09-10 09:00:00'));
        $type = ActivityType::query()->where('is_active', true)->orderBy('sort')->firstOrFail();
        app(ActivityRecorder::class)->record(ActivitySubject::for($lead), $type, $rep, 'Meeting');

        $this->travelTo(Carbon::parse('2026-09-30 09:00:00'));
        $this->assertSame(1, $this->service()->notify(now()));

        // An activity dated before the last one leaves last_activity_at — and the stamp — alone.
        app(ActivityRecorder::class)->record(ActivitySubject::for($lead), $type, $rep, 'Old call', occurredAt: Carbon::parse('2026-09-05 09:00:00'));

        $lead->refresh();
        $this->assertSame('2026-09-10 09:00:00', $lead->last_activity_at?->format('Y-m-d H:i:s'));
        $this->assertNotNull($lead->stale_notified_at);
        $this->assertSame(0, $this->service()->notify(now()));
    }

    #[Test]
    public function converted_terminal_and_unowned_leads_are_skipped(): void
    {
        $rep = $this->salesRep();
        $admin = $this->admin();

        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $converted = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $unqualified = Lead::factory()->create(['owner_id' => $rep->getKey(), 'lead_status_id' => $this->statusOfKind(LeadStatusKind::Unqualified)->getKey()]);
        $unowned = Lead::factory()->create(['owner_id' => null]);
        $deleted = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $qualified = Lead::factory()->create(['owner_id' => $rep->getKey(), 'lead_status_id' => $this->statusOfKind(LeadStatusKind::Qualified)->getKey()]);

        Lead::withoutWorkflowGuard(static fn (): bool => $converted->forceFill([
            'converted_at' => now(),
            'converted_by' => $admin->getKey(),
            'lead_status_id' => LeadStatus::query()->where('kind', LeadStatusKind::Converted->value)->value('id'),
        ])->saveQuietly());
        $deleted->delete();

        $this->travelTo(Carbon::parse('2026-09-16 09:00:00'));

        $this->assertSame(1, $this->service()->notify(now()));

        Notification::assertSentToTimes($rep, LeadStaleNotification::class, 1);
        $this->assertNotNull($qualified->refresh()->stale_notified_at);

        foreach ([$converted, $unqualified, $unowned] as $skipped) {
            $this->assertNull($skipped->refresh()->stale_notified_at);
        }

        $this->assertNull(Lead::withTrashed()->findOrFail($deleted->getKey())->stale_notified_at);
    }

    #[Test]
    public function the_configured_number_of_days_is_honoured(): void
    {
        $rep = $this->salesRep();

        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->travelTo(Carbon::parse('2026-09-16 09:00:00'));

        config()->set('crm.leads.stale_days', 30);
        $this->assertSame(30, LeadStaleService::staleDays());
        $this->assertSame(0, $this->service()->notify(now()));
        Notification::assertNothingSent();

        config()->set('crm.leads.stale_days', 7);
        $this->assertSame(1, $this->service()->notify(now()));
        Notification::assertSentToTimes($rep, LeadStaleNotification::class, 1);
        $this->assertNotNull($lead->refresh()->stale_notified_at);
    }

    #[Test]
    public function a_lead_whose_owner_was_deleted_is_skipped(): void
    {
        $rep = $this->salesRep();

        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        User::query()->whereKey($rep->getKey())->delete();

        $this->travelTo(Carbon::parse('2026-09-16 09:00:00'));

        $this->assertSame(0, $this->service()->notify(now()));
        $this->assertNull($lead->refresh()->stale_notified_at);
        Notification::assertNothingSent();
    }

    #[Test]
    public function the_command_runs_the_pass_and_prints_the_count(): void
    {
        $rep = $this->salesRep();

        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        Lead::factory()->count(2)->create(['owner_id' => $rep->getKey()]);

        $this->travelTo(Carbon::parse('2026-09-16 09:00:00'));

        $this->artisan('leads:notify-stale')
            ->expectsOutputToContain('2 stale lead notifications sent')
            ->assertSuccessful();

        $this->artisan('leads:notify-stale')
            ->expectsOutputToContain('0 stale lead notifications sent')
            ->assertSuccessful();

        Notification::assertSentToTimes($rep, LeadStaleNotification::class, 2);
    }

    private function service(): LeadStaleService
    {
        return app(LeadStaleService::class);
    }

    private function statusOfKind(LeadStatusKind $kind): LeadStatus
    {
        return LeadStatus::query()->where('kind', $kind->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
    }
}
