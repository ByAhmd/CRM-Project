<?php

declare(strict_types=1);

namespace Tests\Feature\Timeline;

use App\Enums\LeadStatusKind;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Livewire\RecordTimeline;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Note;
use App\Models\User;
use App\Services\Leads\ConversionRequest;
use App\Services\Leads\LeadConversionWorkflow;
use App\Services\Leads\LeadStatusWorkflow;
use App\Services\Notes\NoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The RecordTimeline component (module row 12): renders the feed of a record
 * the viewer may see, widens on "load more" up to the cap, refuses the rest,
 * and is embedded at the end of every record page.
 */
final class RecordTimelineTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const string BASE = '2026-09-01 08:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function it_renders_the_newest_entries_and_offers_to_load_more_when_older_ones_exist(): void
    {
        $rep = $this->salesRep();
        $lead = $this->leadWithNotes($rep, 21);

        Livewire::actingAs($rep)
            ->test(RecordTimeline::class, ['subjectType' => Lead::class, 'subjectId' => $lead->getKey()])
            ->assertSet('pages', 1)
            ->assertSee(__('timeline.titles.note_added', ['author' => $rep->name]))
            ->assertSee(self::body(21))
            ->assertSee(self::body(2))
            ->assertDontSee(self::body(1))
            ->assertDontSee(__('timeline.titles.created'))
            ->assertSee(__('timeline.actions.load_more'))
            ->assertDontSee(__('timeline.empty.heading'))
            ->assertSee('2026-09-01')
            ->assertOk();
    }

    #[Test]
    public function load_more_extends_the_list_until_the_feed_is_exhausted(): void
    {
        $rep = $this->salesRep();
        $lead = $this->leadWithNotes($rep, 21);

        Livewire::actingAs($rep)
            ->test(RecordTimeline::class, ['subjectType' => Lead::class, 'subjectId' => $lead->getKey()])
            ->call('loadMore')
            ->assertSet('pages', 2)
            ->assertSee(self::body(1))
            ->assertSee(__('timeline.titles.created'))
            ->assertDontSee(__('timeline.actions.load_more'))
            ->assertDontSee(trans_choice('timeline.hints.capped', 22, ['count' => 22]));
    }

    #[Test]
    public function the_page_count_is_capped_and_a_hint_explains_the_cut(): void
    {
        $rep = $this->salesRep();
        $lead = $this->leadWithNotes($rep, 12);

        $component = Livewire::actingAs($rep)
            ->test(RecordTimeline::class, ['subjectType' => Lead::class, 'subjectId' => $lead->getKey(), 'limit' => 1])
            ->assertSee(self::body(12))
            ->assertDontSee(self::body(11));

        foreach (range(1, RecordTimeline::MAX_PAGES + 2) as $ignored) {
            $component->call('loadMore');
        }

        $component
            ->assertSet('pages', RecordTimeline::MAX_PAGES)
            ->assertSee(self::body(3))
            ->assertDontSee(self::body(2))
            ->assertDontSee(__('timeline.actions.load_more'))
            ->assertSee(trans_choice('timeline.hints.capped', RecordTimeline::MAX_PAGES, ['count' => RecordTimeline::MAX_PAGES]));
    }

    #[Test]
    public function an_empty_feed_shows_the_empty_state(): void
    {
        $rep = $this->salesRep();

        // The creation row would be the only entry; a record that reached the
        // database without firing its events has nothing to show.
        $account = Account::withoutEvents(fn (): Account => Account::factory()->create(['owner_id' => $rep->getKey()]));

        Livewire::actingAs($rep)
            ->test(RecordTimeline::class, ['subjectType' => Account::class, 'subjectId' => $account->getKey()])
            ->assertSee(__('timeline.empty.heading'))
            ->assertSee(__('timeline.empty.description'))
            ->assertDontSee(__('timeline.actions.load_more'))
            ->assertOk();
    }

    #[Test]
    public function a_rep_cannot_open_the_timeline_of_another_reps_lead(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $other->getKey()]);

        Livewire::actingAs($rep)
            ->test(RecordTimeline::class, ['subjectType' => Lead::class, 'subjectId' => $lead->getKey()])
            ->assertForbidden();

        Livewire::actingAs($other)
            ->test(RecordTimeline::class, ['subjectType' => Lead::class, 'subjectId' => $lead->getKey()])
            ->assertSee(__('timeline.titles.created'))
            ->assertOk();
    }

    #[Test]
    public function a_user_without_the_view_permission_is_refused(): void
    {
        $lead = Lead::factory()->create(['owner_id' => $this->salesRep()->getKey()]);

        Livewire::actingAs(User::factory()->create())
            ->test(RecordTimeline::class, ['subjectType' => Lead::class, 'subjectId' => $lead->getKey()])
            ->assertForbidden();
    }

    #[Test]
    public function a_missing_or_unsupported_subject_is_not_found(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(RecordTimeline::class, ['subjectType' => Lead::class, 'subjectId' => 999999])
            ->assertNotFound();

        Livewire::actingAs($admin)
            ->test(RecordTimeline::class, ['subjectType' => User::class, 'subjectId' => $admin->getKey()])
            ->assertNotFound();
    }

    #[Test]
    public function the_subject_cannot_be_swapped_from_the_browser(): void
    {
        $rep = $this->salesRep();
        $mine = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $theirs = Lead::factory()->create(['owner_id' => $this->salesRep()->getKey()]);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($rep)
            ->test(RecordTimeline::class, ['subjectType' => Lead::class, 'subjectId' => $mine->getKey()])
            ->set('subjectId', $theirs->getKey());
    }

    #[Test]
    public function the_page_count_cannot_be_forced_from_the_browser(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($rep)
            ->test(RecordTimeline::class, ['subjectType' => Lead::class, 'subjectId' => $lead->getKey()])
            ->set('pages', 1000);
    }

    #[Test]
    public function the_lead_view_page_embeds_the_timeline_lazily_behind_its_section_heading(): void
    {
        $rep = $this->salesRep();

        $this->travelTo(Carbon::parse(self::BASE));
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->travelTo(Carbon::parse(self::BASE)->addMinutes(5));
        app(NoteService::class)->create($lead, $rep, 'Prefers a morning call.');

        // The page renders first: the section carries the placeholder, the feed arrives on its own request.
        Livewire::actingAs($rep)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->assertSee(__('timeline.section'))
            ->assertSee(__('timeline.hints.loading'))
            ->assertDontSee('Prefers a morning call.')
            ->assertOk();
    }

    #[Test]
    public function the_lead_view_page_renders_the_first_entries_once_the_feed_is_loaded(): void
    {
        $rep = $this->salesRep();

        $this->travelTo(Carbon::parse(self::BASE));
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->travelTo(Carbon::parse(self::BASE)->addMinutes(5));
        app(NoteService::class)->create($lead, $rep, 'Prefers a morning call.');

        Livewire::withoutLazyLoading()
            ->actingAs($rep)
            ->test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->assertSee(__('timeline.section'))
            ->assertSee(__('timeline.titles.note_added', ['author' => $rep->name]))
            ->assertSee('Prefers a morning call.')
            ->assertSee(__('timeline.titles.created'))
            ->assertDontSee(__('timeline.hints.loading'))
            ->assertOk();
    }

    #[Test]
    public function the_contact_account_and_deal_view_pages_embed_the_timeline(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey(), 'first_name' => 'Sara', 'last_name' => 'Origin']);
        app(LeadStatusWorkflow::class)->transition($lead, LeadStatus::query()->where('kind', LeadStatusKind::Qualified->value)->where('is_active', true)->orderBy('sort')->firstOrFail(), $rep, 'Budget confirmed.');
        $result = app(LeadConversionWorkflow::class)->convert($lead->refresh(), new ConversionRequest(accountMode: ConversionRequest::ACCOUNT_NEW, createDeal: true), $rep);

        $account = $result->account;
        $deal = $result->deal;
        $this->assertInstanceOf(Account::class, $account);
        $this->assertInstanceOf(Deal::class, $deal);
        $this->assertInstanceOf(Contact::class, $result->contact);

        $origin = __('timeline.titles.converted_from', ['label' => 'Sara Origin']);

        foreach ([
            [ViewContact::class, $result->contact],
            [ViewAccount::class, $account],
            [ViewDeal::class, $deal],
        ] as [$page, $record]) {
            Livewire::actingAs($rep)
                ->test($page, ['record' => $record->getRouteKey()])
                ->assertSee(__('timeline.section'))
                ->assertSee(__('timeline.hints.loading'))
                ->assertDontSee($origin)
                ->assertOk();

            Livewire::withoutLazyLoading()
                ->actingAs($rep)
                ->test($page, ['record' => $record->getRouteKey()])
                ->assertSee(__('timeline.section'))
                ->assertSee($origin)
                ->assertOk();
        }
    }

    /** A lead created at BASE with one note per minute after it, bodies numbered oldest to newest. */
    private function leadWithNotes(User $owner, int $count): Lead
    {
        $this->travelTo(Carbon::parse(self::BASE));
        $lead = Lead::factory()->create(['owner_id' => $owner->getKey()]);

        foreach (range(1, $count) as $minute) {
            $this->travelTo(Carbon::parse(self::BASE)->addMinutes($minute));
            Note::factory()->create(['lead_id' => $lead->getKey(), 'author_id' => $owner->getKey(), 'body' => self::body($minute)]);
        }

        $this->travelBack();

        return $lead;
    }

    private static function body(int $number): string
    {
        return sprintf('Body of note %02d end', $number);
    }
}
