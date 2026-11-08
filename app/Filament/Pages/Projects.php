<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\NavigationGroup;
use App\Enums\WorkspacePanel;
use App\Exceptions\Projects\InactiveProjectException;
use App\Filament\Concerns\BuildsPageUrlsInOwningPanel;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\PanelAccess;
use App\Services\Projects\CurrentProject;
use App\Services\Projects\ProjectService;
use App\Support\Filament\PanelUrl;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The Sales «المشاريع» page (decision D-30): one card per active project in
 * switcher order — its name and colour, the counts of its leads, contacts,
 * accounts and deals within the viewer's visibility (D-4) and the value of
 * its open deals — with the project the viewer is working in marked. «دخول»
 * makes a project current (CurrentProject) and opens the sales dashboard:
 * from then on the whole workspace is that project's page.
 *
 * Every user who may enter the Sales workspace opens the page; the counts
 * are the viewer's own reach, never the organisation's, and the note under
 * the cards says so. The figures come from ProjectService in a handful of
 * aggregate queries. The link to manage projects shows only to a reader who
 * may both enter Administration (PanelUrl::forReader) and open the projects
 * resource, so it never answers 403.
 */
final class Projects extends Page
{
    use BuildsPageUrlsInOwningPanel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    /** Before the leads: the project is chosen first, the records follow. */
    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.projects';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Sales;
    }

    public static function getNavigationLabel(): string
    {
        return __('projects.navigation.label');
    }

    public function getTitle(): string
    {
        return __('projects.pages.projects.title');
    }

    public function getSubheading(): string
    {
        return __('projects.pages.projects.subheading');
    }

    /** Whoever may enter the Sales workspace switches between its projects (D-30: no membership in v1). */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PanelAccess::class)->canAccess($user, WorkspacePanel::Sales);
    }

    /**
     * One entry per active project, in switcher order: the project, whether
     * it is the viewer's current one, its counts within the viewer's
     * visibility and the formatted value of its open deals.
     *
     * @return list<array{project: Project, current: bool, counts: array{leads_count: int, contacts_count: int, accounts_count: int, deals_count: int, open_deals_count: int, open_deals_amount: float}, open_deals_amount: string}>
     */
    public function getCards(): array
    {
        $viewer = $this->viewer();
        $projects = Project::query()->active()->get();
        $current = app(CurrentProject::class)->for($viewer);
        $counts = app(ProjectService::class)->countsFor(
            $viewer,
            array_values($projects->map(static fn (Project $project): int => (int) $project->getKey())->all()),
        );

        $cards = [];

        foreach ($projects as $project) {
            $figures = $counts[(int) $project->getKey()];

            $cards[] = [
                'project' => $project,
                'current' => $project->is($current),
                'counts' => $figures,
                'open_deals_amount' => DealBoard::money($figures['open_deals_amount']),
            ];
        }

        return $cards;
    }

    /**
     * «دخول»: makes the project the viewer's current one and opens the sales
     * dashboard. The id comes from the browser and is trusted for nothing: an
     * unknown or inactive project — a card that was deactivated since the
     * page loaded — is refused with a toast, never an error page.
     */
    public function enterAction(): Action
    {
        return Action::make('enter')
            ->label(__('projects.actions.enter'))
            ->color('primary')
            ->authorize(static fn (): bool => self::canAccess())
            ->action(function (array $arguments): void {
                $this->enter($arguments['project'] ?? null);
            });
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('manage')
                ->label(__('projects.actions.manage'))
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->url(fn (): ?string => $this->manageUrl())
                ->authorize(fn (): bool => $this->manageUrl() !== null)
                ->visible(fn (): bool => $this->manageUrl() !== null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'cards' => $this->getCards(),
        ];
    }

    private function enter(mixed $id): void
    {
        $viewer = $this->viewer();
        $key = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0;
        $project = $key > 0 ? Project::query()->whereKey($key)->first() : null;

        if (! $project instanceof Project || ! $project->is_active) {
            $this->refuse();

            return;
        }

        try {
            app(CurrentProject::class)->switch($viewer, $project);
        } catch (InactiveProjectException) {
            $this->refuse();

            return;
        }

        Notification::make()
            ->title(__('projects.notifications.switched.title'))
            ->body(__('projects.notifications.switched.body', ['project' => $project->display_name]))
            ->success()
            ->send();

        $this->redirect(SalesDashboard::getUrl());
    }

    private function refuse(): void
    {
        Notification::make()
            ->title(__('projects.validation.inactive'))
            ->danger()
            ->send();
    }

    /** The projects resource, for a reader who may enter Administration and open it; null otherwise. */
    private function manageUrl(): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User || ! ProjectResource::canViewAny()) {
            return null;
        }

        return PanelUrl::forReader($user, ProjectResource::class, static fn (): string => ProjectResource::getUrl());
    }

    private function viewer(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
