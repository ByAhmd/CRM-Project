<?php

declare(strict_types=1);

namespace Tests\Feature\Timeline;

use App\Enums\ActivityKind;
use App\Enums\CloseReasonKind;
use App\Enums\CrmRole;
use App\Enums\LeadStatusKind;
use App\Enums\StageKind;
use App\Enums\TaskStatus;
use App\Enums\TimelineEntryKind;
use App\Filament\Resources\Deals\Schemas\DealInfolist;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Account;
use App\Models\ActivityType;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Note;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Access\RecordAssignmentService;
use App\Services\Activities\ActivityRecorder;
use App\Services\Activities\ActivitySubject;
use App\Services\Activities\TimelineEntry;
use App\Services\Activities\TimelineReader;
use App\Services\Deals\DealCloseService;
use App\Services\Deals\DealStageWorkflow;
use App\Services\Leads\ConversionRequest;
use App\Services\Leads\LeadConversionWorkflow;
use App\Services\Leads\LeadStatusWorkflow;
use App\Services\Notes\NoteService;
use App\Services\Settings\SettingsRepository;
use App\Services\Tasks\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * TimelineReader (module row 12, D-13): every source merged newest first,
 * translated titles, paging by cursor, and the subject's visibility.
 */
final class TimelineReaderTest extends TestCase
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
    public function a_lead_timeline_merges_every_source_newest_first_with_translated_titles(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $other = $this->makeUser(CrmRole::SalesRep, ['name' => 'Other Rep']);

        $this->at(0);
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey(), 'first_name' => 'Nora', 'last_name' => 'Timeline']);
        $new = $lead->status;
        $this->assertNotNull($new);

        $this->at(10);
        $working = $this->statusOfKind(LeadStatusKind::Working);
        app(LeadStatusWorkflow::class)->transition($lead, $working, $rep, 'First call done.');

        $this->at(20);
        $note = app(NoteService::class)->create($lead, $rep, "Prefers email.\nCall after 4pm.");

        $this->at(30);
        $task = app(TaskService::class)->create(['title' => 'Send the brochure', 'due_at' => '2026-09-03 10:00:00', 'lead_id' => $lead->getKey()], $rep);

        $this->at(40);
        $type = ActivityType::query()->where('kind', ActivityKind::Call->value)->where('is_active', true)->orderBy('id')->firstOrFail();
        $activity = app(ActivityRecorder::class)->record(ActivitySubject::for($lead), $type, $rep, 'Intro call', 'Discussed the scope.');

        $this->at(50);
        $attachment = Attachment::factory()->forSubject($lead)->create(['uploaded_by' => $rep->getKey(), 'original_name' => 'quote.pdf']);

        $this->at(60);
        $lead->update(['job_title' => 'CTO']);

        $this->at(70);
        app(TaskService::class)->complete($task, $rep, 'Sent by email.');

        $this->at(80);
        app(RecordAssignmentService::class)->assign($lead->refresh(), $other, $admin);

        $this->at(90);
        $qualified = $this->statusOfKind(LeadStatusKind::Qualified);
        app(LeadStatusWorkflow::class)->transition($lead->refresh(), $qualified, $admin, 'Budget confirmed.');

        $this->at(100);
        app(LeadConversionWorkflow::class)->convert($lead->refresh(), new ConversionRequest(accountMode: ConversionRequest::ACCOUNT_NEW, createDeal: true, dealTitle: 'Nora deal'), $admin);
        $converted = $this->statusOfKind(LeadStatusKind::Converted);

        $entries = $this->reader()->for($lead->refresh(), $admin);

        $this->assertSame([
            TimelineEntryKind::Conversion,
            TimelineEntryKind::StatusChange,
            TimelineEntryKind::Lifecycle,
            TimelineEntryKind::StatusChange,
            TimelineEntryKind::Assignment,
            TimelineEntryKind::Task,
            TimelineEntryKind::Attachment,
            TimelineEntryKind::Activity,
            TimelineEntryKind::Task,
            TimelineEntryKind::Note,
            TimelineEntryKind::StatusChange,
            TimelineEntryKind::Lifecycle,
        ], $entries->map(fn (TimelineEntry $entry): TimelineEntryKind => $entry->kind)->all());

        $this->assertSame([
            __('timeline.titles.converted'),
            __('timeline.titles.status_changed', ['from' => $qualified->display_name, 'to' => $converted->display_name]),
            __('timeline.titles.qualified'),
            __('timeline.titles.status_changed', ['from' => $working->display_name, 'to' => $qualified->display_name]),
            __('timeline.titles.assigned', ['from' => $rep->name, 'to' => $other->name]),
            __('timeline.titles.task_completed', ['title' => 'Send the brochure']),
            __('timeline.titles.attachment_uploaded', ['name' => 'quote.pdf']),
            'Intro call',
            __('timeline.titles.task_created', ['title' => 'Send the brochure']),
            __('timeline.titles.note_added', ['author' => $rep->name]),
            __('timeline.titles.status_changed', ['from' => $new->display_name, 'to' => $working->display_name]),
            __('timeline.titles.created'),
        ], $entries->map(fn (TimelineEntry $entry): string => $entry->title)->all());

        $this->assertOrderedNewestFirst($entries);

        $conversion = $entries->first();
        $this->assertInstanceOf(TimelineEntry::class, $conversion);
        $this->assertSame('audit', $conversion->sourceType);
        $this->assertArrayHasKey(__('timeline.fields.deal'), $conversion->meta);
        $this->assertSame('Nora deal', $conversion->meta[__('timeline.fields.deal')]);
        $this->assertArrayHasKey(__('timeline.fields.contact'), $conversion->links);
        $this->assertArrayHasKey(__('timeline.fields.deal'), $conversion->links);

        $completed = $entries[5];
        $this->assertSame('task_completed', $completed->sourceType);
        $this->assertSame($task->getKey(), $completed->sourceId);
        $this->assertSame(TaskStatus::Completed->getLabel(), $completed->badge);
        $this->assertNotNull($completed->url);

        $file = $entries[6];
        $this->assertSame($attachment->downloadUrl(), $file->url);
        $this->assertSame($rep->name, $file->actor);
        $this->assertSame($attachment->humanSize(), $file->meta[__('timeline.fields.size')]);

        $call = $entries[7];
        $this->assertSame('activity', $call->sourceType);
        $this->assertSame($activity->getKey(), $call->sourceId);
        $this->assertSame(ActivityKind::Call->getLabel(), $call->badge);
        $this->assertSame('Discussed the scope.', $call->body);
        $this->assertSame($rep->name, $call->actor);

        $noteEntry = $entries[9];
        $this->assertSame($note->getKey(), $noteEntry->sourceId);
        $this->assertSame('Prefers email. Call after 4pm.', $noteEntry->body);
        $this->assertSame([], $noteEntry->meta);

        $firstChange = $entries[10];
        $this->assertSame('First call done.', $firstChange->body);
        $this->assertSame($rep->name, $firstChange->actor);

        // The task completion also wrote a system activity; it is represented by the task, not twice.
        $this->assertSame(1, $entries->filter(fn (TimelineEntry $entry): bool => $entry->kind === TimelineEntryKind::Activity)->count());
    }

    #[Test]
    public function attribute_updates_from_the_ledger_are_left_to_the_audit_page(): void
    {
        $rep = $this->salesRep();

        $this->at(0);
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->at(5);
        $lead->update(['job_title' => 'CTO', 'company_name' => 'Ofoq']);

        $this->assertDatabaseHas('activity_log', ['description' => 'lead.updated', 'subject_id' => $lead->getKey()]);

        $entries = $this->reader()->for($lead, $rep);

        $this->assertCount(1, $entries);
        $this->assertSame(TimelineEntryKind::Lifecycle, $entries[0]->kind);
        $this->assertSame(__('timeline.titles.created'), $entries[0]->title);
        $this->assertSame('audit', $entries[0]->sourceType);
    }

    #[Test]
    public function a_pinned_note_carries_the_pinned_flag(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $note = app(NoteService::class)->create($lead, $rep, 'Decision maker.', pinned: true);

        $entry = $this->reader()->for($lead, $rep)->first(fn (TimelineEntry $entry): bool => $entry->sourceType === 'note');

        $this->assertInstanceOf(TimelineEntry::class, $entry);
        $this->assertSame($note->getKey(), $entry->sourceId);
        $this->assertSame([__('timeline.fields.pinned') => __('timeline.values.yes')], $entry->meta);
    }

    #[Test]
    public function the_limit_and_the_before_cursor_page_the_feed_without_duplicates(): void
    {
        $rep = $this->salesRep();

        $this->at(0);
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        foreach (range(1, 7) as $minute) {
            $this->at($minute);
            Note::factory()->create(['lead_id' => $lead->getKey(), 'author_id' => $rep->getKey(), 'body' => "Note {$minute}"]);
        }

        $reader = $this->reader();

        $first = $reader->for($lead, $rep, 3);
        $this->assertSame(['Note 7', 'Note 6', 'Note 5'], $first->map(fn (TimelineEntry $entry): ?string => $entry->body)->all());
        $cursor = $reader->nextCursor($first, 3);
        $this->assertInstanceOf(Carbon::class, $cursor);
        $this->assertTrue($cursor->equalTo(Carbon::parse(self::BASE)->addMinutes(5)));

        $second = $reader->for($lead, $rep, 3, $cursor);
        $this->assertSame(['Note 4', 'Note 3', 'Note 2'], $second->map(fn (TimelineEntry $entry): ?string => $entry->body)->all());
        $cursor = $reader->nextCursor($second, 3);
        $this->assertInstanceOf(Carbon::class, $cursor);

        $third = $reader->for($lead, $rep, 3, $cursor);
        $this->assertSame(['Note 1', __('timeline.titles.created')], $third->map(fn (TimelineEntry $entry): string => $entry->body ?? $entry->title)->all());
        $this->assertNull($reader->nextCursor($third, 3));

        $keys = $first->merge($second)->merge($third)->map(fn (TimelineEntry $entry): string => $entry->key());
        $this->assertSame(8, $keys->count());
        $this->assertSame(8, $keys->unique()->count());
    }

    #[Test]
    public function a_page_never_splits_a_group_of_entries_sharing_one_second(): void
    {
        $rep = $this->salesRep();

        $this->at(0);
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->at(1);
        foreach (range(1, 4) as $number) {
            Note::factory()->create(['lead_id' => $lead->getKey(), 'author_id' => $rep->getKey(), 'body' => "Same second {$number}"]);
        }

        $reader = $this->reader();

        // The cut would fall between the third and the second note; the whole group stays on the page.
        $first = $reader->for($lead, $rep, 2);
        $this->assertSame(
            ['Same second 4', 'Same second 3', 'Same second 2', 'Same second 1'],
            $first->map(fn (TimelineEntry $entry): ?string => $entry->body)->all(),
        );
        $this->assertOrderedNewestFirst($first);

        $cursor = $reader->nextCursor($first, 2);
        $this->assertInstanceOf(Carbon::class, $cursor);
        $this->assertTrue($cursor->equalTo(Carbon::parse(self::BASE)->addMinute()));

        $second = $reader->for($lead, $rep, 2, $cursor);
        $this->assertSame([__('timeline.titles.created')], $second->map(fn (TimelineEntry $entry): string => $entry->title)->all());
        $this->assertNull($reader->nextCursor($second, 2));

        $keys = $first->merge($second)->map(fn (TimelineEntry $entry): string => $entry->key());
        $this->assertSame(5, $keys->count());
        $this->assertSame(5, $keys->unique()->count());
    }

    #[Test]
    public function a_task_due_date_is_shown_in_the_organisation_timezone(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $task = app(TaskService::class)->create(['title' => 'Send the brochure', 'due_at' => '2026-09-03 10:00:00', 'lead_id' => $lead->getKey()], $rep);
        $this->assertNotNull($task->due_at);

        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'UTC'], $admin);

        $entry = $this->reader()->for($lead, $rep)->first(fn (TimelineEntry $entry): bool => $entry->sourceType === 'task');

        $this->assertInstanceOf(TimelineEntry::class, $entry);
        $this->assertSame($task->due_at->copy()->timezone('UTC')->format('Y-m-d H:i'), $entry->meta[__('timeline.fields.due')]);
        $this->assertNotSame($task->due_at->copy()->timezone((string) config('app.timezone'))->format('Y-m-d H:i'), $entry->meta[__('timeline.fields.due')]);
    }

    #[Test]
    public function the_next_cursor_is_null_when_the_page_is_not_full(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $entries = $this->reader()->for($lead, $rep, 5);

        $this->assertCount(1, $entries);
        $this->assertNull($this->reader()->nextCursor($entries, 5));
        $this->assertNull($this->reader()->nextCursor(new Collection));
    }

    #[Test]
    public function a_viewer_who_cannot_view_the_subject_is_refused(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);

        $this->assertCount(1, $this->reader()->for($lead, $rep));
        $this->assertCount(1, $this->reader()->for($lead, $this->admin()));

        // A manager reaches team records only; the rep is not on the manager's team.
        $manager = $this->salesManager($this->makeTeam());
        $this->assertFalse($manager->can('view', $lead));

        $this->expectException(AuthorizationException::class);

        $this->reader()->for($lead, $other);
    }

    #[Test]
    public function a_user_without_the_view_permission_is_refused(): void
    {
        $rep = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $nobody = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->reader()->for($lead, $nobody);
    }

    #[Test]
    public function an_unsupported_subject_is_refused(): void
    {
        $admin = $this->admin();

        $this->expectException(InvalidArgumentException::class);

        $this->reader()->for($admin, $admin);
    }

    #[Test]
    public function a_deal_timeline_shows_stage_changes_with_their_duration_and_the_won_entry(): void
    {
        $rep = $this->salesRep();

        $this->at(0);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'title' => 'Timeline deal']);
        $first = $deal->stage;
        $this->assertNotNull($first);
        $proposal = $this->stageOfKind($deal, StageKind::Open, 1);
        $won = $this->stageOfKind($deal, StageKind::Won);

        $this->at(10);
        app(DealStageWorkflow::class)->transition($deal, $proposal, $rep, 'Proposal sent.');

        $this->at(40);
        $reason = DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
        app(DealCloseService::class)->win($deal->refresh(), $reason, $rep, 'Signed.');

        $entries = $this->reader()->for($deal->refresh(), $rep);

        $this->assertSame([
            TimelineEntryKind::Lifecycle,
            TimelineEntryKind::StageChange,
            TimelineEntryKind::StageChange,
            TimelineEntryKind::Lifecycle,
        ], $entries->map(fn (TimelineEntry $entry): TimelineEntryKind => $entry->kind)->all());

        $this->assertSame(__('timeline.titles.won'), $entries[0]->title);
        $this->assertSame('Signed.', $entries[0]->body);
        $this->assertArrayHasKey(__('timeline.fields.close_reason'), $entries[0]->meta);
        $this->assertSame($reason->display_name, $entries[0]->meta[__('timeline.fields.close_reason')]);
        $this->assertArrayHasKey(__('timeline.fields.amount'), $entries[0]->meta);

        $this->assertSame(__('timeline.titles.stage_changed', ['from' => $proposal->display_name, 'to' => $won->display_name]), $entries[1]->title);
        $this->assertSame(DealInfolist::formatDuration(30 * 60), $entries[1]->meta[__('timeline.fields.duration')]);
        $this->assertSame('Signed.', $entries[1]->body);

        $this->assertSame(__('timeline.titles.stage_changed', ['from' => $first->display_name, 'to' => $proposal->display_name]), $entries[2]->title);
        $this->assertSame('Proposal sent.', $entries[2]->body);
        $this->assertSame($rep->name, $entries[2]->actor);
        $this->assertArrayHasKey(__('timeline.fields.duration'), $entries[2]->meta);

        $this->assertSame(__('timeline.titles.created'), $entries[3]->title);
        $this->assertOrderedNewestFirst($entries);
    }

    #[Test]
    public function an_account_promoted_by_a_won_deal_shows_the_customer_entry(): void
    {
        $rep = $this->salesRep();

        $this->at(0);
        $account = Account::factory()->create(['owner_id' => $rep->getKey()]);
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey(), 'account_id' => $account->getKey(), 'title' => 'First order']);
        $reason = DealCloseReason::query()->where('kind', CloseReasonKind::Won->value)->where('is_active', true)->orderBy('sort')->firstOrFail();

        $this->at(30);
        app(DealCloseService::class)->win($deal, $reason, $rep);

        $entry = $this->reader()->for($account->refresh(), $rep)->first();

        $this->assertInstanceOf(TimelineEntry::class, $entry);
        $this->assertSame(TimelineEntryKind::Lifecycle, $entry->kind);
        $this->assertSame(__('timeline.titles.became_customer'), $entry->title);
        $this->assertSame('First order', $entry->meta[__('timeline.fields.deal')]);
    }

    #[Test]
    public function records_created_by_a_conversion_carry_the_conversion_entry_of_their_origin_lead(): void
    {
        $rep = $this->salesRep();

        $this->at(0);
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey(), 'first_name' => 'Sara', 'last_name' => 'Origin', 'company_name' => 'Ofoq']);
        app(LeadStatusWorkflow::class)->transition($lead, $this->statusOfKind(LeadStatusKind::Qualified), $rep, 'Budget confirmed.');

        $this->at(15);
        $result = app(LeadConversionWorkflow::class)->convert($lead->refresh(), new ConversionRequest(
            accountMode: ConversionRequest::ACCOUNT_NEW,
            createDeal: true,
            dealTitle: 'Ofoq deal',
            note: 'Signed the NDA.',
        ), $rep);

        $account = $result->account;
        $contact = $result->contact;
        $deal = $result->deal;
        $this->assertInstanceOf(Account::class, $account);
        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertInstanceOf(Deal::class, $deal);

        foreach ([$contact, $account, $deal] as $record) {
            $entries = $this->reader()->for($record, $rep);
            $conversion = $entries->first(fn (TimelineEntry $entry): bool => $entry->kind === TimelineEntryKind::Conversion);

            $this->assertInstanceOf(TimelineEntry::class, $conversion, $record::class);
            $this->assertSame(__('timeline.titles.converted_from', ['label' => 'Sara Origin']), $conversion->title);
            $this->assertSame('conversion', $conversion->sourceType);
            $this->assertSame('Signed the NDA.', $conversion->body);
            $this->assertSame($rep->name, $conversion->actor);
            $this->assertSame(LeadResource::getUrl('view', ['record' => $lead]), $conversion->url);
            $this->assertTrue($conversion->occurredAt->equalTo(Carbon::parse(self::BASE)->addMinutes(15)));
            $this->assertOrderedNewestFirst($entries);
        }

        // A record that was not converted from a lead has no conversion entry.
        $plain = Contact::factory()->create(['owner_id' => $rep->getKey()]);
        $this->assertNull($this->reader()->for($plain, $rep)->first(fn (TimelineEntry $entry): bool => $entry->kind === TimelineEntryKind::Conversion));
    }

    #[Test]
    public function links_to_other_records_are_only_produced_for_a_viewer_who_may_open_them(): void
    {
        $rep = $this->salesRep();
        $manager = $this->salesManager();

        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        app(LeadStatusWorkflow::class)->transition($lead, $this->statusOfKind(LeadStatusKind::Qualified), $rep, 'Budget confirmed.');
        $result = app(LeadConversionWorkflow::class)->convert($lead->refresh(), new ConversionRequest(accountMode: ConversionRequest::ACCOUNT_NEW, createDeal: true), $rep);

        // The manager (own team only, and not on the rep's team) may view the lead through the admin path below,
        // but the created records belong to the rep alone.
        $admin = $this->admin();
        $conversion = $this->reader()->for($lead->refresh(), $admin)->first(fn (TimelineEntry $entry): bool => $entry->kind === TimelineEntryKind::Conversion);
        $this->assertInstanceOf(TimelineEntry::class, $conversion);
        $this->assertCount(3, $conversion->links);

        $team = $this->makeTeam(manager: $manager);
        $manager->forceFill(['team_id' => $team->getKey()])->save();
        $lead->forceFill(['owner_id' => $manager->getKey()])->saveQuietly();

        $conversion = $this->reader()->for($lead->refresh(), $manager->refresh())->first(fn (TimelineEntry $entry): bool => $entry->kind === TimelineEntryKind::Conversion);
        $this->assertInstanceOf(TimelineEntry::class, $conversion);
        $this->assertSame([], $conversion->links);
        $this->assertSame($result->contact->full_name, $conversion->meta[__('timeline.fields.contact')]);
    }

    /**
     * Newest first; two entries sharing a second follow the documented
     * tie-break — source type ascending, then the newest source id.
     *
     * @param  Collection<int, TimelineEntry>  $entries
     */
    private function assertOrderedNewestFirst(Collection $entries): void
    {
        $previous = null;

        foreach ($entries as $entry) {
            if ($previous instanceof TimelineEntry) {
                $message = sprintf('%s (%s) came before %s (%s)', $previous->key(), $previous->occurredAt->toDateTimeString(), $entry->key(), $entry->occurredAt->toDateTimeString());

                $this->assertTrue($previous->occurredAt->greaterThanOrEqualTo($entry->occurredAt), $message);

                if ($previous->occurredAt->equalTo($entry->occurredAt)) {
                    $types = strcmp($previous->sourceType, $entry->sourceType);

                    $this->assertTrue($types < 0 || ($types === 0 && $previous->sourceId > $entry->sourceId), $message);
                }
            }

            $previous = $entry;
        }
    }

    private function at(int $minutes): void
    {
        $this->travelTo(Carbon::parse(self::BASE)->addMinutes($minutes));
    }

    private function reader(): TimelineReader
    {
        return app(TimelineReader::class);
    }

    private function statusOfKind(LeadStatusKind $kind): LeadStatus
    {
        return LeadStatus::query()->where('kind', $kind->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
    }

    private function stageOfKind(Deal $deal, StageKind $kind, int $position = 0): PipelineStage
    {
        return PipelineStage::query()
            ->where('pipeline_id', $deal->pipeline_id)
            ->where('kind', $kind->value)
            ->orderBy('sort')
            ->orderBy('id')
            ->skip($position)
            ->firstOrFail();
    }
}
