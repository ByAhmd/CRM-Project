<?php

declare(strict_types=1);

namespace App\Services\Activities;

use App\Enums\ActivityLogEvent;
use App\Enums\TimelineEntryKind;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Schemas\DealInfolist;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Account;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealStageLog;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use App\Models\Note;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use App\Services\Audit\ActivityLogPresenter;
use App\Services\Audit\ActivityLogQuery;
use App\Services\Notes\ReadableNoteScope;
use App\Services\Settings\SettingsRepository;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The chronological feed of a lead, a contact, an account or a deal
 * (module row 12, section 3 "Read models", D-13).
 *
 * Every source — activities, notes, tasks, status and stage logs,
 * attachments and the audit ledger — is read newest-first with the page
 * limit applied per source, then merged in PHP, sorted by occurrence and cut
 * to the page. A `before` cursor (the oldest time of the previous page)
 * continues the feed; ties are broken by source type and id so the order is
 * stable across requests. The cursor is exclusive, so a page never ends
 * inside a group of entries sharing one second: when the cut would split
 * such a group (a conversion and its status log, a task and its activity),
 * the rest of the group is fetched and the page runs a few entries past the
 * limit rather than losing them on the next page.
 *
 * Entries follow the subject's visibility: the viewer must be able to view
 * the subject, and nothing narrower is applied to what belongs to it —
 * attachments, logs and the notes written on it. Activities and tasks linked
 * to the subject are shown as their own policies allow (a link to the
 * subject is one of their reading paths). The one narrowing is an account's
 * notes: those written on its contacts and deals keep the scope of that
 * contact or deal (ReadableNoteScope). Links to other records are only produced
 * when the viewer may open them. Timeline entries are permanent because
 * their sources are (D-13): soft-deleted notes, tasks and attachments leave
 * the feed until they are restored, and the ledger never forgets.
 */
final class TimelineReader
{
    public const int DEFAULT_LIMIT = 20;

    public const int EXCERPT_LENGTH = 200;

    /** Audit events shown on the feed; attribute diffs and settings stay on the audit page. */
    private const array LEDGER_EVENTS = [
        'created', 'restored', 'deleted', 'assigned', 'merged', 'converted', 'became_customer', 'qualified',
        'won', 'lost', 'reopened',
    ];

    public function __construct(
        private readonly ActivityLogQuery $ledger,
        private readonly SettingsRepository $settings,
        private readonly ReadableNoteScope $readableNotes,
    ) {}

    /**
     * The newest page of the subject's feed, or the page older than `$before`.
     *
     * The page holds `$limit` entries — plus the remainder of a group sharing
     * the last entry's second, so that paging with {@see nextCursor()} skips
     * nothing and repeats nothing.
     *
     * @return Collection<int, TimelineEntry>
     */
    public function for(Model $subject, User $viewer, int $limit = self::DEFAULT_LIMIT, ?Carbon $before = null): Collection
    {
        self::assertSupported($subject);

        if (! $viewer->can('view', $subject)) {
            throw new AuthorizationException;
        }

        $limit = max(1, $limit);
        $sorted = $this->collect($subject, $viewer, $limit, $before, at: null);
        $page = $sorted->take($limit);
        $last = $page->last();
        $first = $sorted->get($limit);

        if ($last instanceof TimelineEntry && $first instanceof TimelineEntry && $first->occurredAt->equalTo($last->occurredAt)) {
            $page = $page
                ->merge($this->collect($subject, $viewer, $limit, before: null, at: $last->occurredAt))
                ->unique(static fn (TimelineEntry $entry): string => $entry->key())
                ->sort(self::order(...));
        }

        return $page->values();
    }

    /**
     * The cursor for the page after the given one: the oldest time on a full
     * page, nothing once the feed is exhausted. Pass it back as `$before`;
     * it is exclusive, which is safe because a page never splits the group
     * of entries sharing its oldest second.
     *
     * @param  Collection<int, TimelineEntry>  $entries
     */
    public function nextCursor(Collection $entries, int $limit = self::DEFAULT_LIMIT): ?Carbon
    {
        if ($entries->count() < max(1, $limit)) {
            return null;
        }

        $last = $entries->last();

        return $last instanceof TimelineEntry ? $last->occurredAt->copy() : null;
    }

    public static function supports(Model $subject): bool
    {
        return $subject instanceof Lead
            || $subject instanceof Contact
            || $subject instanceof Account
            || $subject instanceof Deal;
    }

    private static function assertSupported(Model $subject): void
    {
        if (! self::supports($subject)) {
            throw new InvalidArgumentException(sprintf('%s has no timeline.', $subject::class));
        }
    }

    /** Newest first; ties by source type then by the newest source id. */
    private static function order(TimelineEntry $a, TimelineEntry $b): int
    {
        return $b->occurredAt <=> $a->occurredAt
            ?: strcmp($a->sourceType, $b->sourceType)
            ?: $b->sourceId <=> $a->sourceId;
    }

    /**
     * Every source merged, de-duplicated and sorted: the newest rows older
     * than `$before`, or — when `$at` is given — every row at exactly that
     * second, used to complete the group a page boundary fell into.
     *
     * @return Collection<int, TimelineEntry>
     */
    private function collect(Model $subject, User $viewer, int $limit, ?Carbon $before, ?Carbon $at): Collection
    {
        return collect([
            ...$this->activities($subject, $viewer, $limit, $before, $at),
            ...$this->notes($subject, $viewer, $limit, $before, $at),
            ...$this->tasks($subject, $viewer, $limit, $before, $at),
            ...$this->statusChanges($subject, $limit, $before, $at),
            ...$this->stageChanges($subject, $limit, $before, $at),
            ...$this->attachments($subject, $viewer, $limit, $before, $at),
            ...$this->ledgerEntries($subject, $viewer, $limit, $before, $at),
            ...$this->originConversions($subject, $viewer, $limit, $before, $at),
        ])
            ->unique(static fn (TimelineEntry $entry): string => $entry->key())
            ->sort(self::order(...))
            ->values();
    }

    /**
     * The page window of one source: rows older than the cursor, one more
     * than the limit so a group split by the cut can be detected — or every
     * row at exactly `$at`, a group bounded by human action rather than by a
     * limit.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function window(Builder $query, string $column, int $limit, ?Carbon $before, ?Carbon $at): Builder
    {
        if ($at !== null) {
            return $query->where($column, '=', $at);
        }

        return $query
            ->when($before, static fn (Builder $query, Carbon $before): Builder => $query->where($column, '<', $before))
            ->limit($limit + 1);
    }

    /**
     * Activities logged against the subject. A completion entry written by a
     * task (task_id set) is represented by the task itself.
     *
     * @return list<TimelineEntry>
     */
    private function activities(Model $subject, User $viewer, int $limit, ?Carbon $before, ?Carbon $at): array
    {
        $column = Activity::subjectColumnFor($subject);

        if ($column === null) {
            return [];
        }

        $query = Activity::query()
            ->where($column, $subject->getKey())
            ->whereNull('task_id')
            // The linked records are what ActivityPolicy::view falls back to when the
            // viewer does not reach the activity's owner; loading them here keeps the
            // per-entry authorisation free of queries.
            ->with(['owner', 'type', 'deal', 'lead', 'contact', 'account'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        return self::window($query, 'occurred_at', $limit, $before, $at)
            ->get()
            ->map(fn (Activity $activity): TimelineEntry => new TimelineEntry(
                kind: TimelineEntryKind::Activity,
                occurredAt: $activity->occurred_at,
                title: $activity->subject,
                sourceType: 'activity',
                sourceId: (int) $activity->getKey(),
                body: self::excerpt($activity->body),
                actor: $activity->owner?->name,
                url: $viewer->can('view', $activity) ? ActivityResource::getUrl('view', ['record' => $activity]) : null,
                badge: $activity->kind->getLabel(),
                meta: array_filter([
                    __('timeline.fields.outcome') => $activity->outcome,
                ], static fn (?string $value): bool => filled($value)),
            ))
            ->all();
    }

    /**
     * Notes on the subject, at their creation time; edits live in the ledger.
     * An account's feed also finds the notes written on its contacts and
     * deals (NoteService copies the account onto them), so it keeps only those
     * whose contact or deal the viewer may read (ReadableNoteScope, D-4).
     *
     * @return list<TimelineEntry>
     */
    private function notes(Model $subject, User $viewer, int $limit, ?Carbon $before, ?Carbon $at): array
    {
        $query = Note::query()
            ->where(self::noteColumnFor($subject), $subject->getKey())
            ->when($subject instanceof Account, fn (Builder $notes): Builder => $this->readableNotes->apply($notes, $viewer))
            ->with('author')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return self::window($query, 'created_at', $limit, $before, $at)
            ->get()
            ->map(fn (Note $note): TimelineEntry => new TimelineEntry(
                kind: TimelineEntryKind::Note,
                occurredAt: self::moment($note->created_at),
                title: __('timeline.titles.note_added', ['author' => self::userName($note->author)]),
                sourceType: 'note',
                sourceId: (int) $note->getKey(),
                body: $note->excerpt(self::EXCERPT_LENGTH),
                actor: $note->author?->name,
                meta: $note->is_pinned ? [__('timeline.fields.pinned') => __('timeline.values.yes')] : [],
            ))
            ->all();
    }

    /**
     * Tasks linked to the subject: one entry when created, one more when completed.
     *
     * @return list<TimelineEntry>
     */
    private function tasks(Model $subject, User $viewer, int $limit, ?Carbon $before, ?Carbon $at): array
    {
        $column = Task::subjectColumnFor($subject);

        if ($column === null) {
            return [];
        }

        // The linked records serve TaskPolicy::view's fallback without a query per entry.
        $base = Task::query()->where($column, $subject->getKey())->with(['assignee', 'deal', 'lead', 'contact', 'account']);

        $created = self::window((clone $base)->orderByDesc('created_at')->orderByDesc('id'), 'created_at', $limit, $before, $at)
            ->get()
            ->map(fn (Task $task): TimelineEntry => $this->taskEntry($task, $viewer, completed: false));

        $completed = self::window((clone $base)->whereNotNull('completed_at')->orderByDesc('completed_at')->orderByDesc('id'), 'completed_at', $limit, $before, $at)
            ->get()
            ->map(fn (Task $task): TimelineEntry => $this->taskEntry($task, $viewer, completed: true));

        return [...$created->all(), ...$completed->all()];
    }

    private function taskEntry(Task $task, User $viewer, bool $completed): TimelineEntry
    {
        $occurredAt = $completed ? ($task->completed_at ?? $task->created_at) : $task->created_at;

        return new TimelineEntry(
            kind: TimelineEntryKind::Task,
            occurredAt: self::moment($occurredAt),
            title: __($completed ? 'timeline.titles.task_completed' : 'timeline.titles.task_created', ['title' => $task->title]),
            sourceType: $completed ? 'task_completed' : 'task',
            sourceId: (int) $task->getKey(),
            body: $completed ? null : self::excerpt($task->description),
            actor: $task->assignee?->name,
            url: $viewer->can('view', $task) ? TaskResource::getUrl('view', ['record' => $task]) : null,
            badge: $task->status->getLabel(),
            meta: array_filter([
                __('timeline.fields.priority') => $task->priority->getLabel(),
                __('timeline.fields.due') => $task->due_at?->timezone($this->settings->timezone())->format('Y-m-d H:i'),
            ], static fn (?string $value): bool => filled($value)),
        );
    }

    /**
     * Lead status transitions (D-7).
     *
     * @return list<TimelineEntry>
     */
    private function statusChanges(Model $subject, int $limit, ?Carbon $before, ?Carbon $at): array
    {
        if (! $subject instanceof Lead) {
            return [];
        }

        $query = LeadStatusLog::query()
            ->where('lead_id', $subject->getKey())
            ->with(['fromStatus', 'toStatus', 'changer'])
            ->orderByDesc('changed_at')
            ->orderByDesc('id');

        return self::window($query, 'changed_at', $limit, $before, $at)
            ->get()
            ->map(fn (LeadStatusLog $log): TimelineEntry => new TimelineEntry(
                kind: TimelineEntryKind::StatusChange,
                occurredAt: $log->changed_at,
                title: __('timeline.titles.status_changed', [
                    'from' => self::displayName($log->fromStatus),
                    'to' => self::displayName($log->toStatus),
                ]),
                sourceType: 'lead_status_log',
                sourceId: (int) $log->getKey(),
                body: self::excerpt($log->notes),
                actor: $log->changer?->name,
                badge: $log->toStatus?->display_name,
            ))
            ->all();
    }

    /**
     * Deal stage transitions with the time spent in the previous stage (D-8).
     *
     * @return list<TimelineEntry>
     */
    private function stageChanges(Model $subject, int $limit, ?Carbon $before, ?Carbon $at): array
    {
        if (! $subject instanceof Deal) {
            return [];
        }

        $query = DealStageLog::query()
            ->where('deal_id', $subject->getKey())
            ->with(['fromStage', 'toStage', 'changer'])
            ->orderByDesc('changed_at')
            ->orderByDesc('id');

        return self::window($query, 'changed_at', $limit, $before, $at)
            ->get()
            ->map(fn (DealStageLog $log): TimelineEntry => new TimelineEntry(
                kind: TimelineEntryKind::StageChange,
                occurredAt: $log->changed_at,
                title: __('timeline.titles.stage_changed', [
                    'from' => self::displayName($log->fromStage),
                    'to' => self::displayName($log->toStage),
                ]),
                sourceType: 'deal_stage_log',
                sourceId: (int) $log->getKey(),
                body: self::excerpt($log->notes),
                actor: $log->changer?->name,
                badge: $log->toStage?->display_name,
                meta: $log->duration_seconds === null
                    ? []
                    : [__('timeline.fields.duration') => DealInfolist::formatDuration((int) $log->duration_seconds)],
            ))
            ->all();
    }

    /**
     * Files attached to the subject; the download link only for a viewer who may download.
     *
     * @return list<TimelineEntry>
     */
    private function attachments(Model $subject, User $viewer, int $limit, ?Carbon $before, ?Carbon $at): array
    {
        $query = Attachment::query()
            ->where('attachable_type', $subject->getMorphClass())
            ->where('attachable_id', $subject->getKey())
            ->with('uploader')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return self::window($query, 'created_at', $limit, $before, $at)
            ->get()
            ->map(function (Attachment $attachment) use ($subject, $viewer): TimelineEntry {
                $attachment->setRelation('attachable', $subject);

                return new TimelineEntry(
                    kind: TimelineEntryKind::Attachment,
                    occurredAt: self::moment($attachment->created_at),
                    title: __('timeline.titles.attachment_uploaded', ['name' => $attachment->original_name]),
                    sourceType: 'attachment',
                    sourceId: (int) $attachment->getKey(),
                    body: self::excerpt($attachment->description),
                    actor: $attachment->uploader?->name,
                    url: $viewer->can('download', $attachment) ? $attachment->downloadUrl() : null,
                    badge: __('attachments.options.mime.'.$attachment->mimeFamily()),
                    meta: [__('timeline.fields.size') => $attachment->humanSize()],
                );
            })
            ->all();
    }

    /**
     * Business events from the audit ledger whose subject is the record:
     * assignments, merges, conversions, lifecycle events. Attribute diffs
     * (`*.updated`) and the duplicates of the status and stage logs stay on
     * the audit page.
     *
     * @return list<TimelineEntry>
     */
    private function ledgerEntries(Model $subject, User $viewer, int $limit, ?Carbon $before, ?Carbon $at): array
    {
        $query = $this->ledger->forSubject($subject)
            ->whereIn('description', self::ledgerEventValues($subject))
            ->with('causer');

        return self::window($query, 'created_at', $limit, $before, $at)
            ->get()
            ->map(fn (ActivityLog $log): TimelineEntry => $this->ledgerEntry($log, $viewer))
            ->all();
    }

    /**
     * The `lead.converted` event of the lead a deal, a contact or an account
     * was created from, shown on the created record as its origin.
     *
     * @return list<TimelineEntry>
     */
    private function originConversions(Model $subject, User $viewer, int $limit, ?Carbon $before, ?Carbon $at): array
    {
        $leadIds = match (true) {
            $subject instanceof Deal => $subject->lead_id === null ? [] : [(int) $subject->lead_id],
            $subject instanceof Contact => Lead::withTrashed()->where('converted_contact_id', $subject->getKey())->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            $subject instanceof Account => Lead::withTrashed()->where('converted_account_id', $subject->getKey())->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            default => [],
        };

        if ($leadIds === []) {
            return [];
        }

        $query = $this->ledger->all()
            ->where('subject_type', (new Lead)->getMorphClass())
            ->whereIn('subject_id', $leadIds)
            ->where('description', ActivityLogEvent::LeadConverted->value)
            ->with(['causer', 'subject']);

        return self::window($query, 'created_at', $limit, $before, $at)
            ->get()
            ->map(function (ActivityLog $log) use ($viewer): TimelineEntry {
                $presenter = new ActivityLogPresenter($log);
                $lead = $log->subject;
                $label = $lead instanceof Lead ? $lead->full_name : $presenter->subjectLabel();

                return new TimelineEntry(
                    kind: TimelineEntryKind::Conversion,
                    occurredAt: self::moment($log->created_at),
                    title: __('timeline.titles.converted_from', ['label' => $label]),
                    sourceType: 'conversion',
                    sourceId: (int) $log->getKey(),
                    body: self::excerpt(self::property($log, 'note')),
                    actor: $presenter->causerLabel(),
                    url: $lead instanceof Lead && $viewer->can('view', $lead) ? LeadResource::getUrl('view', ['record' => $lead]) : null,
                    badge: __('timeline.fields.lead'),
                );
            })
            ->all();
    }

    private function ledgerEntry(ActivityLog $log, User $viewer): TimelineEntry
    {
        $presenter = new ActivityLogPresenter($log);
        $event = ActivityLogEvent::tryFromDescription($log->description);
        $suffix = $event === null ? '' : (explode('.', $event->value, 2)[1] ?? '');
        $occurredAt = self::moment($log->created_at);
        $actor = $presenter->causerLabel();
        $sourceId = (int) $log->getKey();

        return match ($suffix) {
            'assigned' => $this->assignmentEntry($log, $occurredAt, $actor, $sourceId),
            'converted' => $this->conversionEntry($log, $viewer, $occurredAt, $actor, $sourceId),
            'merged' => new TimelineEntry(
                kind: TimelineEntryKind::Lifecycle,
                occurredAt: $occurredAt,
                title: __('timeline.titles.merged', ['label' => self::property($log, 'merged_label') ?? __('common.placeholders.empty')]),
                sourceType: 'audit',
                sourceId: $sourceId,
                actor: $actor,
                badge: $presenter->actionLabel(),
            ),
            'became_customer' => new TimelineEntry(
                kind: TimelineEntryKind::Lifecycle,
                occurredAt: $occurredAt,
                title: __('timeline.titles.became_customer'),
                sourceType: 'audit',
                sourceId: $sourceId,
                actor: $actor,
                badge: $presenter->actionLabel(),
                meta: array_filter([__('timeline.fields.deal') => self::property($log, 'deal_title')], static fn (?string $value): bool => filled($value)),
            ),
            'created', 'restored', 'deleted', 'qualified', 'reopened' => new TimelineEntry(
                kind: TimelineEntryKind::Lifecycle,
                occurredAt: $occurredAt,
                title: __('timeline.titles.'.$suffix),
                sourceType: 'audit',
                sourceId: $sourceId,
                body: self::excerpt(self::property($log, 'note')),
                actor: $actor,
                badge: $presenter->actionLabel(),
            ),
            'won', 'lost' => new TimelineEntry(
                kind: TimelineEntryKind::Lifecycle,
                occurredAt: $occurredAt,
                title: __('timeline.titles.'.$suffix),
                sourceType: 'audit',
                sourceId: $sourceId,
                body: self::excerpt(self::property($log, $suffix === 'lost' ? 'lost_notes' : 'note')),
                actor: $actor,
                badge: $presenter->actionLabel(),
                meta: array_filter([
                    __('timeline.fields.amount') => self::property($log, 'amount') === null ? null : trim(self::property($log, 'amount').' '.(self::property($log, 'currency') ?? '')),
                    __('timeline.fields.close_reason') => self::property($log, 'close_reason'),
                ], static fn (?string $value): bool => filled($value)),
            ),
            default => new TimelineEntry(
                kind: TimelineEntryKind::Audit,
                occurredAt: $occurredAt,
                title: $presenter->actionLabel(),
                sourceType: 'audit',
                sourceId: $sourceId,
                actor: $actor,
            ),
        };
    }

    private function assignmentEntry(ActivityLog $log, Carbon $occurredAt, string $actor, int $sourceId): TimelineEntry
    {
        $previous = self::property($log, 'previous_owner_name');
        $owner = self::property($log, 'owner_name');

        return new TimelineEntry(
            kind: TimelineEntryKind::Assignment,
            occurredAt: $occurredAt,
            title: $owner === null
                ? __('timeline.titles.unassigned')
                : __('timeline.titles.assigned', [
                    'from' => $previous ?? __('assignment.placeholders.unassigned'),
                    'to' => $owner,
                ]),
            sourceType: 'audit',
            sourceId: $sourceId,
            actor: $actor,
            badge: $owner ?? __('assignment.placeholders.unassigned'),
        );
    }

    /**
     * The lead's own conversion: the account, contact and deal it produced,
     * each linked when the viewer may open it.
     */
    private function conversionEntry(ActivityLog $log, User $viewer, Carbon $occurredAt, string $actor, int $sourceId): TimelineEntry
    {
        $meta = [];
        $links = [];

        $accountId = self::propertyId($log, 'account_id');
        $contactId = self::propertyId($log, 'contact_id');
        $dealId = self::propertyId($log, 'deal_id');

        $account = $accountId === null ? null : Account::withTrashed()->find($accountId);
        $contact = $contactId === null ? null : Contact::withTrashed()->find($contactId);
        $deal = $dealId === null ? null : Deal::withTrashed()->find($dealId);

        $accountName = self::property($log, 'account_name') ?? $account?->name;
        $contactName = self::property($log, 'contact_name') ?? $contact?->full_name;
        $dealTitle = self::property($log, 'deal_title') ?? $deal?->title;

        if ($accountName !== null) {
            $meta[__('timeline.fields.account')] = $accountName;
        }

        if ($contactName !== null) {
            $meta[__('timeline.fields.contact')] = $contactName;
        }

        if ($dealTitle !== null) {
            $meta[__('timeline.fields.deal')] = $dealTitle;
        }

        if ($account instanceof Account && $viewer->can('view', $account)) {
            $links[__('timeline.fields.account')] = AccountResource::getUrl('view', ['record' => $account]);
        }

        if ($contact instanceof Contact && $viewer->can('view', $contact)) {
            $links[__('timeline.fields.contact')] = ContactResource::getUrl('view', ['record' => $contact]);
        }

        if ($deal instanceof Deal && $viewer->can('view', $deal)) {
            $links[__('timeline.fields.deal')] = DealResource::getUrl('view', ['record' => $deal]);
        }

        return new TimelineEntry(
            kind: TimelineEntryKind::Conversion,
            occurredAt: $occurredAt,
            title: __('timeline.titles.converted'),
            sourceType: 'audit',
            sourceId: $sourceId,
            body: self::excerpt(self::property($log, 'note')),
            actor: $actor,
            badge: (new ActivityLogPresenter($log))->actionLabel(),
            meta: $meta,
            links: $links,
        );
    }

    /**
     * The ledger event values shown for the subject's entity, e.g. `lead.assigned`.
     *
     * @return list<string>
     */
    private static function ledgerEventValues(Model $subject): array
    {
        $prefix = match (true) {
            $subject instanceof Lead => 'lead',
            $subject instanceof Contact => 'contact',
            $subject instanceof Account => 'account',
            default => 'deal',
        };

        return array_values(array_filter(
            array_map(static fn (ActivityLogEvent $event): string => $event->value, ActivityLogEvent::cases()),
            static fn (string $value): bool => str_starts_with($value, $prefix.'.')
                && in_array(explode('.', $value, 2)[1], self::LEDGER_EVENTS, true),
        ));
    }

    private static function noteColumnFor(Model $subject): string
    {
        return match (true) {
            $subject instanceof Lead => 'lead_id',
            $subject instanceof Contact => 'contact_id',
            $subject instanceof Account => 'account_id',
            default => 'deal_id',
        };
    }

    /** A copy of the given moment as the framework Carbon, now when the column is unset. */
    private static function moment(?DateTimeInterface $value): Carbon
    {
        return Carbon::instance($value ?? Carbon::now());
    }

    private static function userName(?User $user): string
    {
        return $user === null ? __('activity.sources.system') : $user->name;
    }

    private static function displayName(LeadStatus|PipelineStage|null $lookup): string
    {
        return $lookup === null ? __('common.placeholders.empty') : $lookup->display_name;
    }

    private static function property(ActivityLog $log, string $key): ?string
    {
        $properties = $log->properties;
        $value = $properties instanceof Collection ? $properties->get($key) : null;

        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private static function propertyId(ActivityLog $log, string $key): ?int
    {
        $value = self::property($log, $key);

        return $value === null || ! is_numeric($value) ? null : (int) $value;
    }

    private static function excerpt(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return Str::limit(Str::squish($text), self::EXCERPT_LENGTH);
    }
}
