<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Authorisation;

use App\Enums\NotificationEvent;
use App\Enums\UserStatus;
use App\Filament\Resources\Activities\Pages\CreateActivity;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\NotificationPreference;
use App\Notifications\DealStageChangedNotification;
use App\Services\Deals\DealStageWorkflow;
use App\Support\Notifications\NotificationChannels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Authorisation audit probes: information that reaches a user who is outside
 * the record's scope or no longer allowed in the application.
 */
final class DisclosureProbeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function the_duplicate_warning_does_not_name_a_contact_outside_the_reps_reach(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $foreign = Contact::factory()->create([
            'owner_id' => $other->getKey(),
            'first_name' => 'Hidden',
            'last_name' => 'Customerson',
            'email' => 'hidden.customer@example.com',
        ]);

        $this->assertFalse($rep->can('view', $foreign), 'precondition');

        Livewire::actingAs($rep)
            ->test(CreateLead::class)
            ->fillForm(['email' => 'hidden.customer@example.com'])
            ->assertDontSee('Customerson');
    }

    #[Test]
    public function a_disabled_user_receives_no_mail_notifications(): void
    {
        config(['mail.default' => 'smtp']);
        $this->assertTrue(NotificationChannels::mailIsConfigured(), 'precondition');

        $rep = $this->salesRep();
        NotificationPreference::factory()->for($rep)->ofEvent(NotificationEvent::DealStageChanged)->withMail()->create();
        $rep->forceFill(['status' => UserStatus::Disabled])->save();
        $rep = $rep->fresh() ?? $rep;

        $this->assertNotContains(
            'mail',
            NotificationChannels::for($rep, NotificationEvent::DealStageChanged),
            'a switched-off account keeps receiving deal titles and amounts by mail after it lost panel access',
        );
    }

    #[Test]
    public function a_disabled_owner_is_not_told_about_their_deal_moving(): void
    {
        Notification::fake();
        Mail::fake();
        $rep = $this->salesRep();
        $admin = $this->admin();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $rep->forceFill(['status' => UserStatus::Disabled])->save();

        app(DealStageWorkflow::class)->transition($deal, app(DealStageWorkflow::class)->allowedStages($deal)->firstOrFail(), $admin);

        Notification::assertNotSentTo($rep, DealStageChangedNotification::class);
    }

    #[Test]
    public function an_admin_may_choose_the_owner_of_an_activity_they_log(): void
    {
        // ActivityForm renders OwnerSelect for the `activity` group and
        // ActivityForm::log() honours a submitted owner, but OwnerSelect is
        // gated on `activity.assign`, a key App\Enums\Permission does not
        // define — so the field is dead for every role, super_admin included.
        Livewire::actingAs($this->admin())
            ->test(CreateActivity::class)
            ->assertFormFieldVisible('owner_id');
    }
}
