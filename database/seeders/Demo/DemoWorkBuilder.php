<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\ActivityDirection;
use App\Enums\ActivityKind;
use App\Enums\DealStatus;
use App\Enums\LeadStatusKind;
use App\Enums\RecurrenceFrequency;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Models\Account;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Note;
use App\Models\SavedView;
use App\Models\Task;
use App\Models\User;
use App\Services\Activities\ActivityRecorder;
use App\Services\Activities\ActivitySubject;
use App\Services\Attachments\AttachmentStorage;
use App\Services\Notes\NoteService;
use App\Services\Tasks\TaskService;
use App\Services\Views\SavedViewService;
use App\Services\Views\TableState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The day-to-day work around the records (decision A-10): tasks and
 * activities per batch, and — once per run — notes, the two attachments and
 * two saved views.
 *
 * - tasks go through TaskService: six open, five overdue, eight completed
 *   (each completion writes a system `task` activity on the record), one a
 *   manager hands to a rep of the team, and one weekly task whose completion
 *   schedules the next occurrence;
 * - sixty activities over the last sixty days go through ActivityRecorder,
 *   which moves each lead's and deal's last activity forward;
 * - notes go through NoteService (one pinned, one naming a colleague, who is
 *   notified), the files through AttachmentStorage exactly as an upload
 *   parked on the attachments disk, the views through SavedViewService.
 */
final class DemoWorkBuilder
{
    public const ACTIVITIES_PER_BATCH = 60;

    public function __construct(
        private readonly TaskService $tasks,
        private readonly ActivityRecorder $activities,
        private readonly NoteService $notes,
        private readonly AttachmentStorage $attachments,
        private readonly SavedViewService $views,
    ) {}

    public function build(DemoContext $context, int $batch): void
    {
        $leads = $this->openLeads($context, $batch);
        $deals = array_values(array_filter($context->dealsByBatch[$batch], static fn (Deal $deal): bool => $deal->status === DealStatus::Open));

        $this->buildTasks($context, $batch, $leads, $deals);
        $this->buildActivities($context, $batch, $leads, $context->dealsByBatch[$batch]);

        if ($batch === 0) {
            $this->buildNotes($context, $leads, $deals);
            $this->buildAttachments($context, $deals);
            $this->buildSavedViews($context);
        }
    }

    /**
     * The batch's leads that are neither converted nor still untouched, in creation order.
     *
     * @return list<Lead>
     */
    private function openLeads(DemoContext $context, int $batch): array
    {
        $kinds = LeadStatus::query()->pluck('kind', 'id')->all();
        $leads = array_slice($context->leads, $batch * DemoLeadsBuilder::PER_BATCH, DemoLeadsBuilder::PER_BATCH);

        return array_values(array_filter($leads, static function (Lead $lead) use ($kinds): bool {
            $kind = $kinds[(int) $lead->lead_status_id] ?? null;
            $value = $kind instanceof LeadStatusKind ? $kind : LeadStatusKind::tryFrom((string) $kind);

            return $value !== LeadStatusKind::Converted && $value !== LeadStatusKind::New;
        }));
    }

    /**
     * @param  list<Lead>  $leads
     * @param  list<Deal>  $deals
     */
    private function buildTasks(DemoContext $context, int $batch, array $leads, array $deals): void
    {
        $open = [TaskKind::Call, TaskKind::FollowUp, TaskKind::Meeting, TaskKind::Task, TaskKind::Call, TaskKind::Meeting];

        for ($t = 0; $t < 19; $t++) {
            $subject = $t % 2 === 0 ? $leads[intdiv($t, 2) % count($leads)] : $deals[intdiv($t, 2) % count($deals)];
            $owner = $context->userById($subject->owner_id);
            $arabic = $this->isArabic($subject);
            $kind = $t < 6 ? $open[$t] : ($t % 3 === 0 ? TaskKind::Call : TaskKind::FollowUp);

            if ($t < 6) {
                $due = $context->now->copy()->addDays(1 + $t)->setTime(10, 0);
                $created = $context->ago(3 + $t, $batch);
            } elseif ($t < 11) {
                $due = $context->ago($t - 5)->setTime(11, 0);
                $created = $context->ago(8 + $t, $batch);
            } else {
                $created = $context->ago(20 - ($t - 11) * 2, $batch);
                $due = $created->copy()->addDays(2)->setTime(12, 0);
            }

            $task = $context->as($owner, $created, fn (): Task => $this->tasks->create([
                'title' => $this->taskTitle($kind, $arabic, $subject),
                'description' => $arabic ? 'مراجعة الملاحظات السابقة قبل التواصل.' : 'Review the previous notes before reaching out.',
                'kind' => $kind,
                'priority' => [TaskPriority::Medium, TaskPriority::High, TaskPriority::Low, TaskPriority::Urgent][$t % 4],
                'due_at' => $due,
                'starts_at' => $kind === TaskKind::Meeting || $kind === TaskKind::Call ? $due : null,
                'ends_at' => $kind === TaskKind::Meeting ? $due->copy()->addHour() : ($kind === TaskKind::Call ? $due->copy()->addMinutes(30) : null),
                'reminder_at' => $t < 6 ? $due->copy()->subHour() : null,
                'assignee_id' => $owner->getKey(),
                $subject instanceof Lead ? 'lead_id' : 'deal_id' => $subject->getKey(),
                'recurrence_frequency' => RecurrenceFrequency::None,
            ], $owner));

            $context->record('tasks', (int) $task->getKey());

            if ($t >= 11) {
                $context->as($owner, $created->copy()->addDay(), fn (): Task => $this->tasks->complete($task, $owner, $arabic ? 'تم التواصل وتحديد الخطوة التالية.' : 'Done; next step agreed.'));
            }
        }

        $this->handedOverTask($context, $batch);
        $this->recurringTask($context, $batch);
    }

    /** A to-do the Riyadh manager assigns to the rep of the team (needs task.assign, D-4). */
    private function handedOverTask(DemoContext $context, int $batch): void
    {
        $manager = $context->user('sales_manager');
        $rep = $context->user('sales_rep');

        $task = $context->as($manager, $context->ago(2, $batch), fn (): Task => $this->tasks->create([
            'title' => DemoAccountsBuilder::suffixed('تحديث بيانات العملاء المتوقعين قبل اجتماع الإدارة', $batch),
            'description' => 'التأكد من تواريخ الإغلاق المتوقعة والمبالغ في الصفقات المفتوحة.',
            'kind' => TaskKind::Task,
            'priority' => TaskPriority::High,
            'due_at' => $context->now->copy()->addDays(3)->setTime(13, 0),
            'assignee_id' => $rep->getKey(),
        ], $manager));

        $context->record('tasks', (int) $task->getKey());
    }

    /** A weekly pipeline review: last week's occurrence is done, so the next one is scheduled. */
    private function recurringTask(DemoContext $context, int $batch): void
    {
        $manager = $context->user('sales_manager');
        $due = $context->ago(7)->setTime(9, 0);

        $task = $context->as($manager, $context->ago(15, $batch), fn (): Task => $this->tasks->create([
            'title' => DemoAccountsBuilder::suffixed('مراجعة خط المبيعات الأسبوعية', $batch),
            'description' => 'مراجعة الصفقات المفتوحة مع الفريق وتحديث التوقعات.',
            'kind' => TaskKind::Meeting,
            'priority' => TaskPriority::High,
            'due_at' => $due,
            'starts_at' => $due,
            'ends_at' => $due->copy()->addHour(),
            'recurrence_frequency' => RecurrenceFrequency::Weekly,
            'recurrence_interval' => 1,
        ], $manager));

        $context->record('tasks', (int) $task->getKey());
        $context->as($manager, $due->copy()->addHours(2), fn (): Task => $this->tasks->complete($task, $manager));
    }

    /**
     * @param  list<Lead>  $leads
     * @param  list<Deal>  $deals
     */
    private function buildActivities(DemoContext $context, int $batch, array $leads, array $deals): void
    {
        $lines = DemoDataset::activityLines();
        $kinds = [ActivityKind::Call, ActivityKind::Email, ActivityKind::Meeting, ActivityKind::Call, ActivityKind::Email];
        $types = ActivityType::query()->where('is_system', true)->get()->keyBy(static fn (ActivityType $type): string => $type->kind->value);
        $contacts = array_values(array_filter(
            array_merge(...array_map(static fn (Account $account): array => $context->contactsByAccount[(int) $account->getKey()] ?? [], $context->accountsByBatch[$batch])),
            static fn (Contact $contact): bool => $contact->account_id !== null,
        ));

        for ($k = 0; $k < self::ACTIVITIES_PER_BATCH; $k++) {
            /** @var Lead|Deal|Contact $record */
            $record = match ($k % 3) {
                0 => $leads[intdiv($k, 3) % count($leads)],
                1 => $deals[intdiv($k, 3) % count($deals)],
                default => $contacts[intdiv($k, 3) % count($contacts)],
            };

            $owner = $context->userById($record->owner_id);
            $kind = $kinds[$k % count($kinds)];
            $arabic = $this->isArabic($record);
            [$subjectLine, $body] = $lines[$kind->value][$arabic ? 'ar' : 'en'][$k % 2];
            $type = $types->get($kind->value);
            $created = Carbon::instance($record->created_at ?? $context->now);
            $moment = $context->ago(59 - $k, $batch);

            if ($moment->lessThan($created->copy()->addHours(6))) {
                $moment = $created->copy()->addHours(6);
            }

            if (! $type instanceof ActivityType) {
                continue;
            }

            $activity = $context->as($owner, $moment, fn (): Activity => $this->activities->record(
                ActivitySubject::for($record),
                $type,
                $owner,
                $subjectLine,
                $body,
                $moment->greaterThan($context->now) ? $context->now->copy()->subMinutes(5) : $moment->copy(),
                $kind->hasDirection() ? ($k % 4 === 0 ? ActivityDirection::Inbound : ActivityDirection::Outbound) : null,
                $kind === ActivityKind::Meeting ? 45 : ($kind === ActivityKind::Call ? 5 + $k % 20 : null),
                null,
            ));

            $context->record('activities', (int) $activity->getKey());
        }
    }

    /**
     * @param  list<Lead>  $leads
     * @param  list<Deal>  $deals
     */
    private function buildNotes(DemoContext $context, array $leads, array $deals): void
    {
        $accounts = $context->accountsByBatch[0];

        foreach (array_slice($accounts, 0, 5) as $index => $account) {
            $arabic = $this->isArabic($account);
            $body = $arabic
                ? "العميل يفضّل التواصل صباحًا عبر الجوال.\nيجدد العقد في بداية كل سنة مالية."
                : "Prefers morning calls on mobile.\nRenews at the start of each fiscal year.";

            $note = $this->note($context, $account, $context->userById($account->owner_id), $body, $context->ago(40 - $index * 3));

            if ($index === 0) {
                $context->as($context->userById($account->owner_id), $context->ago(36), fn (): Note => $this->notes->pin($note, $context->userById($account->owner_id)));
            }
        }

        foreach (array_slice($deals, 0, 4) as $index => $deal) {
            $owner = $context->userById($deal->owner_id);
            $manager = $context->managerOf($owner);
            $mention = $manager->isNot($owner) && $index === 0;
            $author = $mention ? $manager : $owner;
            $arabic = $this->isArabic($deal);
            $body = $arabic
                ? 'طلب العميل خصمًا على الترخيص السنوي؛ نحتاج موافقة قبل إرسال العرض النهائي.'
                : 'The customer asked for a discount on the annual licence; approval needed before the final proposal.';

            $this->note($context, $deal, $author, $body, $context->ago(20 - $index * 2), $mention ? [(int) $owner->getKey()] : []);
        }

        foreach (array_slice($leads, 0, 3) as $index => $lead) {
            $arabic = $this->isArabic($lead);
            $body = $arabic ? 'طلب عرضًا توضيحيًا لفريق العمليات.' : 'Asked for a demo for the operations team.';

            $this->note($context, $lead, $context->userById($lead->owner_id), $body, $context->ago(12 - $index * 3));
        }
    }

    /**
     * @param  list<int>  $mentions
     */
    private function note(DemoContext $context, Model $subject, User $author, string $body, Carbon $moment, array $mentions = []): Note
    {
        $note = $context->as($author, $moment, fn (): Note => $this->notes->create($subject, $author, $body, false, $mentions));
        $context->record('notes', (int) $note->getKey());

        return $note;
    }

    /**
     * A proposal PDF on a deal and a site photo on an account, parked on the
     * attachments disk like a Filament upload and stored from there.
     *
     * @param  list<Deal>  $deals
     */
    private function buildAttachments(DemoContext $context, array $deals): void
    {
        $account = $context->accountsByBatch[0][0];
        $deal = $deals[min(5, count($deals) - 1)];

        $files = [
            [$deal, 'proposal.pdf', DemoFiles::pdf('Commercial proposal - '.Str::ascii((string) $deal->title)), $this->isArabic($deal) ? 'العرض الفني والمالي' : 'Technical and commercial proposal', $context->ago(18)],
            [$account, 'site-photo.png', DemoFiles::png(), $this->isArabic($account) ? 'صورة موقع التركيب' : 'Installation site photo', $context->ago(30)],
        ];

        $disk = Storage::disk((string) config('crm.attachments.disk'));

        foreach ($files as [$subject, $name, $bytes, $description, $moment]) {
            $directory = 'tmp/demo-data/'.Str::uuid();
            $path = $directory.'/'.$name;
            $uploader = $context->userById($subject->owner_id);

            $disk->put($path, $bytes);

            try {
                $attachment = $context->as($uploader, $moment, fn (): Attachment => $this->attachments->store($path, $subject, $uploader, $description));
            } finally {
                $disk->deleteDirectory($directory);
            }

            $context->attachments[] = $attachment;
            $context->record('attachments', (int) $attachment->getKey());
        }
    }

    private function buildSavedViews(DemoContext $context): void
    {
        $manager = $context->user('sales_manager');
        $rep = $context->user('sales_rep2');

        $views = [
            $context->as($manager, $context->ago(25), fn (): SavedView => $this->views->save(
                $manager,
                'leads',
                'عملاء محتملون بأولوية عالية',
                new TableState(['priority' => ['values' => ['high']]], 'created_at', 'desc', null, null),
                shared: true,
            )),
            $context->as($rep, $context->ago(22), fn (): SavedView => $this->views->save(
                $rep,
                'deals',
                'My open deals',
                new TableState(['status' => ['values' => [DealStatus::Open->value]]], 'expected_close_date', 'asc', null, null),
                default: true,
            )),
        ];

        foreach ($views as $view) {
            $context->record('saved_views', (int) $view->getKey());
        }
    }

    private function taskTitle(TaskKind $kind, bool $arabic, Lead|Deal $subject): string
    {
        $label = $subject instanceof Lead ? ($subject->company_name ?? $subject->full_name) : $subject->title;

        $title = match ($kind) {
            TaskKind::Call => $arabic ? 'الاتصال بـ' : 'Call',
            TaskKind::Meeting => $arabic ? 'اجتماع مع' : 'Meeting with',
            TaskKind::FollowUp => $arabic ? 'متابعة' : 'Follow up with',
            TaskKind::Task => $arabic ? 'تجهيز عرض السعر لـ' : 'Prepare the quotation for',
        };

        return Str::limit($title.' '.$label, 180, '');
    }

    /** A record is Arabic when its name is written in Arabic script. */
    private function isArabic(Model $record): bool
    {
        $text = match (true) {
            $record instanceof Lead => $record->company_name ?? $record->full_name,
            $record instanceof Deal => $record->title,
            $record instanceof Account => $record->name,
            $record instanceof Contact => $record->full_name,
            default => '',
        };

        return preg_match('/\p{Arabic}/u', (string) $text) === 1;
    }
}
