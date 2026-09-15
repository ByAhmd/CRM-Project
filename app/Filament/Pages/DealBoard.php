<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\DealStatus;
use App\Enums\NavigationGroup;
use App\Enums\StageKind;
use App\Exceptions\Deals\InvalidDealTransitionException;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Support\DealActions;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Deals\DealStageWorkflow;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;

/**
 * The deal kanban (decision D-12): one column per stage of the chosen
 * pipeline, cards within the actor's visibility scope (D-4).
 *
 * Dragging a card into an open stage calls DealStageWorkflow through the same
 * rules as the "change stage" action; dropping it on the Won or Lost column
 * does not move it — it opens the "mark as won" / "mark as lost" modal so the
 * close reason is captured, and the board re-renders from the database.
 * Closed columns show the deals closed in the last CLOSED_WINDOW_DAYS days.
 */
final class DealBoard extends Page
{
    public const CARDS_PER_PAGE = 25;

    /** "Load more" stops here: one column never renders more than MAX_PAGES × CARDS_PER_PAGE cards. */
    public const MAX_PAGES = 20;

    public const CLOSED_WINDOW_DAYS = 90;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static ?int $navigationSort = 21;

    protected string $view = 'filament.pages.deal-board';

    public ?int $pipelineId = null;

    /**
     * Visible card count per stage id. Locked so only loadMore() can raise it:
     * a browser must not be able to force every visible deal into one render.
     *
     * @var array<int, int>
     */
    #[Locked]
    public array $limits = [];

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Sales;
    }

    public static function getNavigationLabel(): string
    {
        return __('deal_board.navigation');
    }

    public function getTitle(): string
    {
        return __('deal_board.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Deal::class);
    }

    public function mount(): void
    {
        $this->pipelineId = self::defaultPipelineId();
    }

    public function updatedPipelineId(mixed $value): void
    {
        $this->limits = [];

        if ($value === null || $value === '' || ! $this->pipelines()->contains(fn (Pipeline $pipeline): bool => $pipeline->getKey() === (int) $value)) {
            Notification::make()->title(__('deal_board.validation.pipeline_inactive'))->danger()->send();

            $this->pipelineId = self::defaultPipelineId();

            return;
        }

        $this->pipelineId = (int) $value;
    }

    /**
     * The active pipelines offered in the switcher.
     *
     * @return Collection<int, Pipeline>
     */
    public function pipelines(): Collection
    {
        return Pipeline::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * One entry per stage of the pipeline — open stages first, then won, then
     * lost — with the visible deals, the total count and the summed amount.
     *
     * @return SupportCollection<int, array{stage: PipelineStage, deals: Collection<int, Deal>, count: int<0, max>, total: string, limit: int}>
     */
    public function columns(): SupportCollection
    {
        if ($this->pipelineId === null) {
            return new SupportCollection;
        }

        $order = [StageKind::Open->value => 0, StageKind::Won->value => 1, StageKind::Lost->value => 2];

        return PipelineStage::query()
            ->where('pipeline_id', $this->pipelineId)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (PipelineStage $stage): string => sprintf('%d-%08d-%08d', $order[$stage->kind->value], $stage->sort, $stage->getKey()))
            ->values()
            ->map(function (PipelineStage $stage): array {
                $query = $this->dealsIn($stage);
                $limit = $this->limitFor((int) $stage->getKey());

                return [
                    'stage' => $stage,
                    'deals' => (clone $query)
                        ->orderByRaw('expected_close_date IS NULL')
                        ->orderBy('expected_close_date')
                        ->orderBy('id')
                        ->limit($limit)
                        ->get(),
                    'count' => (clone $query)->count(),
                    'total' => self::money((float) (clone $query)->sum('amount')),
                    'limit' => $limit,
                ];
            });
    }

    /**
     * Shows one more page of cards in a column. The stage id comes from the
     * browser, so a stage that is not on the current board is ignored (it
     * would only grow the locked array serialised into every snapshot), and
     * the column stops growing at MAX_PAGES pages, as RecordTimeline does.
     */
    public function loadMore(int $stageId): void
    {
        if ($this->pipelineId === null || ! PipelineStage::query()->where('pipeline_id', $this->pipelineId)->whereKey($stageId)->exists()) {
            return;
        }

        $this->limits[$stageId] = min(self::MAX_PAGES * self::CARDS_PER_PAGE, $this->limitFor($stageId) + self::CARDS_PER_PAGE);
    }

    /**
     * Called by the drag-and-drop handler with the dragged card and the
     * column it landed in.
     */
    public function moveDeal(int $dealId, int $stageId): void
    {
        $deal = DealResource::getEloquentQuery()->find($dealId);

        if (! $deal instanceof Deal) {
            abort(404);
        }

        $stage = PipelineStage::query()->whereKey($stageId)->where('pipeline_id', $this->pipelineId)->first();

        if (! $stage instanceof PipelineStage) {
            Notification::make()
                ->title(__('deal_board.notifications.refused'))
                ->body(__('deal_board.validation.stage_outside_pipeline'))
                ->danger()
                ->send();

            return;
        }

        // A closed card cannot be dragged anywhere: the same refusal the
        // "change stage" action gives, rather than a policy 403.
        if ($deal->isClosed()) {
            Notification::make()
                ->title(__('deal_board.notifications.refused'))
                ->body(__('deals.validation.already_closed'))
                ->danger()
                ->send();

            return;
        }

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        if ($stage->kind !== StageKind::Open) {
            abort_unless($user->can('close', $deal), 403);

            $this->mountAction($stage->kind === StageKind::Won ? 'markWon' : 'markLost', ['deal' => $dealId]);

            return;
        }

        abort_unless($user->can('changeStage', $deal), 403);

        try {
            app(DealStageWorkflow::class)->transition($deal, $stage, $user);
        } catch (InvalidDealTransitionException $exception) {
            Notification::make()
                ->title(__('deal_board.notifications.refused'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('deal_board.notifications.moved', ['deal' => $deal->title, 'stage' => $stage->display_name]))
            ->success()
            ->send();
    }

    public function markWonAction(): Action
    {
        return DealActions::markWon()->record(fn (array $arguments): ?Deal => $this->dealFromArguments($arguments));
    }

    public function markLostAction(): Action
    {
        return DealActions::markLost()->record(fn (array $arguments): ?Deal => $this->dealFromArguments($arguments));
    }

    /** Money formatted for the current locale, in the organisation currency unless given (D-8). */
    public static function money(float $amount, ?string $currency = null): string
    {
        return (string) Number::currency($amount, $currency ?? DealResource::currency(), app()->getLocale());
    }

    /** The default active pipeline, else the first active one. */
    private static function defaultPipelineId(): ?int
    {
        $id = Pipeline::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('sort')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'pipelines' => $this->pipelines(),
            'columns' => $this->columns(),
            'currency' => DealResource::currency(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function dealFromArguments(array $arguments): ?Deal
    {
        $dealId = $arguments['deal'] ?? null;

        if (! is_numeric($dealId)) {
            return null;
        }

        $deal = DealResource::getEloquentQuery()->find((int) $dealId);

        return $deal instanceof Deal ? $deal : null;
    }

    /**
     * The visible deals sitting in a stage: open deals of an open stage, and
     * for a closed stage the deals closed there within the recent window.
     *
     * @return Builder<Deal>
     */
    private function dealsIn(PipelineStage $stage): Builder
    {
        $query = DealResource::getEloquentQuery()
            ->where('stage_id', $stage->getKey())
            ->where('status', DealStatus::fromStageKind($stage->kind)->value);

        return match ($stage->kind) {
            StageKind::Open => $query,
            StageKind::Won => $query->where('won_at', '>=', now()->subDays(self::CLOSED_WINDOW_DAYS)),
            StageKind::Lost => $query->where('lost_at', '>=', now()->subDays(self::CLOSED_WINDOW_DAYS)),
        };
    }

    private function limitFor(int $stageId): int
    {
        return max(self::CARDS_PER_PAGE, (int) ($this->limits[$stageId] ?? self::CARDS_PER_PAGE));
    }
}
