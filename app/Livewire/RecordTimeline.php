<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use App\Services\Activities\TimelineEntry;
use App\Services\Activities\TimelineReader;
use App\Services\Settings\SettingsRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The timeline of one lead, contact, account or deal (module row 12, D-12).
 *
 * Embedded lazily at the end of the record's view page, so the page renders
 * first and the feed's queries run once the section scrolls into view. The
 * subject is resolved from the locked type/id pair on every request and the
 * viewer must pass the subject's `view` policy — the feed inherits the
 * record's visibility (D-4). "Load more" widens the page rather than
 * appending: the reader is asked for pages × PER_PAGE entries, capped at
 * MAX_PAGES so a browser can never force an unbounded render.
 */
final class RecordTimeline extends Component
{
    public const int PER_PAGE = TimelineReader::DEFAULT_LIMIT;

    public const int MAX_PAGES = 10;

    /** The subject's morph alias or class name. */
    #[Locked]
    public string $subjectType = '';

    #[Locked]
    public int $subjectId = 0;

    #[Locked]
    public int $limit = self::PER_PAGE;

    /** An ISO-8601 moment; only entries older than it are listed. */
    #[Locked]
    public ?string $before = null;

    #[Locked]
    public int $pages = 1;

    public function mount(): void
    {
        $subject = $this->subject();

        abort_unless($this->viewer()->can('view', $subject), 403);
    }

    public function loadMore(): void
    {
        $this->pages = min(self::MAX_PAGES, $this->pages + 1);
    }

    /** What the section shows until the lazily mounted feed arrives. */
    public function placeholder(): string
    {
        return Blade::render(
            '<x-filament::loading-section :loading-label="$label" height="6rem" />',
            ['label' => __('timeline.hints.loading')],
        );
    }

    public function render(): View
    {
        $subject = $this->subject();
        $viewer = $this->viewer();

        abort_unless($viewer->can('view', $subject), 403);

        $limit = max(1, $this->limit) * max(1, $this->pages);
        $reader = app(TimelineReader::class);
        $entries = $reader->for($subject, $viewer, $limit, $this->beforeMoment());
        $next = $reader->nextCursor($entries, $limit);
        $timezone = app(SettingsRepository::class)->timezone();

        return view('livewire.record-timeline', [
            'groups' => $entries->groupBy(static fn (TimelineEntry $entry): string => $entry->day($timezone)),
            'timezone' => $timezone,
            'hasMore' => $next !== null && $this->pages < self::MAX_PAGES,
            'capped' => $next !== null && $this->pages >= self::MAX_PAGES,
            'shown' => $entries->count(),
        ]);
    }

    private function subject(): Model
    {
        $class = Relation::getMorphedModel($this->subjectType) ?? $this->subjectType;

        abort_unless(in_array($class, [Lead::class, Contact::class, Account::class, Deal::class], true), 404);

        /** @var class-string<Lead|Contact|Account|Deal> $class */
        $subject = $class::withTrashed()->find($this->subjectId);

        abort_unless($subject instanceof Model, 404);

        return $subject;
    }

    private function viewer(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function beforeMoment(): ?Carbon
    {
        if ($this->before === null || trim($this->before) === '') {
            return null;
        }

        return Carbon::parse($this->before);
    }
}
