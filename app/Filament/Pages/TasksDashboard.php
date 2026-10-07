<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\MyWorkBucket;
use App\Filament\Concerns\BuildsPageUrlsInOwningPanel;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\TaskActions;
use App\Filament\Widgets\UpcomingFollowUpsWidget;
use App\Models\Task;
use App\Models\User;
use App\Services\Settings\OrganisationCalendar;
use App\Services\Tasks\MyWorkFeed;
use App\Services\Tasks\MyWorkSection;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\On;

/**
 * «عملي» (My Work), the home page of the Tasks workspace (decision D-27,
 * replacing the D-23 attention list): the reader's own tasks in buckets on
 * the organisation calendar — Overdue · Today · Next 7 days · Later · No date
 * — plus what they completed in the last seven days, each bucket with its
 * count (MyWorkFeed decides what falls where). Every row carries the shared
 * visual language (TaskVisuals: status pill, priority flag, relative due,
 * checklist meter, the assigner's avatar) and the quick controls of
 * TaskActions: the completion check (the D-25 warning first when checklist
 * items are open, «تراجع» in the toast), the status pill offering only the
 * moves the reader's policy allows, and the reschedule chips for those who
 * may edit the dates (never the handed-out assignee, D-14 amendment). Quick
 * add is the page's primary action. Below the buckets the follow-ups coming
 * up across the reader's reach stay, as on the D-23 home.
 *
 * The page presents only: the row actions resolve their task from the id the
 * browser sends through MyWorkFeed::find() — the reader's own tasks, inside
 * their scope — and each action authorises itself through TaskPolicy and
 * writes through TaskService.
 */
final class TasksDashboard extends BaseDashboard
{
    use BuildsPageUrlsInOwningPanel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected string $view = 'filament.pages.my-work';

    public static function getNavigationLabel(): string
    {
        return __('my_work.navigation.label');
    }

    public function getTitle(): string
    {
        return __('my_work.pages.title');
    }

    public function getSubheading(): string
    {
        return __('my_work.pages.subheading');
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            UpcomingFollowUpsWidget::class,
        ];
    }

    /**
     * One column at every width: the follow-ups list runs under the buckets,
     * full width.
     *
     * @return array<string, int>
     */
    public function getColumns(): array
    {
        return [
            'default' => 1,
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // The page's primary action; the N shortcut and the phone bar's
            // «+» open it here (data-crm-shortcut, ?action=quickAdd).
            TaskActions::quickAdd(),
        ];
    }

    /** The completion check of a row: one click, the D-25 warning first when items are open. */
    public function quickCompleteAction(): Action
    {
        return TaskActions::quickComplete()->record(fn (array $arguments): ?Task => $this->rowTask($arguments));
    }

    /** The status pill of a row: the moves the reader may make, in one small dialog. */
    public function changeStatusAction(): Action
    {
        return TaskActions::changeStatus()->record(fn (array $arguments): ?Task => $this->rowTask($arguments));
    }

    /** The due date of a row, for those who may edit it: the reschedule chips. */
    public function rescheduleAction(): Action
    {
        return TaskActions::reschedule()->record(fn (array $arguments): ?Task => $this->rowTask($arguments));
    }

    /** A task changed elsewhere on the page (the toast's «تراجع»): render again. */
    #[On(TaskActions::CHANGED_EVENT)]
    public function refreshTasks(): void {}

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $reader = auth()->user();
        $sections = $reader instanceof User ? app(MyWorkFeed::class)->sections($reader) : [];
        $open = array_sum(array_map(
            static fn (MyWorkSection $section): int => $section->bucket->holdsOpenWork() ? $section->total : 0,
            $sections,
        ));

        return [
            'sections' => $sections,
            'reader' => $reader,
            'hasOpenWork' => $open > 0,
            'canAddTasks' => $reader instanceof User && $reader->can('create', Task::class),
            'listUrl' => TaskResource::canViewAny() ? TaskResource::getUrl('index') : null,
            'boardUrl' => TasksBoard::canAccess() ? TasksBoard::getUrl() : null,
        ];
    }

    /**
     * Where a bucket's «عرض … أخرى» link leads: the task list narrowed to
     * the same set the bucket counts — the reader's own tasks («مهامي فقط»)
     * on the matching tab, bounded to the bucket's days on the organisation
     * calendar where the tab alone is wider (Next 7 days, Later, No date).
     * Completed recently opens the reader's completed tasks.
     */
    public static function listUrlFor(MyWorkBucket $bucket): string
    {
        $today = app(OrganisationCalendar::class)->today();
        $mine = ['mine' => ['isActive' => true]];

        [$tab, $filters] = match ($bucket) {
            MyWorkBucket::Overdue => ['overdue', $mine],
            MyWorkBucket::Today => ['today', $mine],
            MyWorkBucket::NextSevenDays => ['upcoming', $mine + ['due_at' => [
                'from' => $today->addDay()->toDateString(),
                'until' => $today->addDays(MyWorkFeed::NEXT_DAYS)->toDateString(),
            ]]],
            MyWorkBucket::Later => ['upcoming', $mine + ['due_at' => ['from' => $today->addDays(MyWorkFeed::NEXT_DAYS + 1)->toDateString()]]],
            MyWorkBucket::NoDate => ['my', $mine + ['no_due' => ['isActive' => true]]],
            MyWorkBucket::CompletedRecently => ['completed', $mine],
        };

        return TaskResource::getUrl('index', ['tab' => $tab, 'filters' => $filters]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function rowTask(array $arguments): ?Task
    {
        $reader = auth()->user();

        return $reader instanceof User ? app(MyWorkFeed::class)->find($reader, $arguments['task'] ?? null) : null;
    }
}
