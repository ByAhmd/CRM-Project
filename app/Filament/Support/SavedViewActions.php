<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\Permission;
use App\Filament\Concerns\HasSavedViews;
use App\Models\SavedView;
use App\Models\User;
use App\Services\Views\SavedViewService;
use App\Services\Views\TableState;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The saved-view header actions every list page adds with one line (A-8):
 *
 *   `[...SavedViewActions::for($this), CreateAction::make()]`
 *
 * - `views`: a dropdown of the views the user may apply, grouped under "my
 *   views" and "shared views" (a shared view carries a suffix, the actor's
 *   default another), plus `manage` — a modal to set a default or delete;
 * - `saveView`: stores the page's current filter / sort / search / column
 *   state under a name, optionally shared (permission) or default;
 * - `clearView`: back to the table's own defaults.
 *
 * The views the user may see are loaded once per request and shared by the
 * dropdown and the manage modal. The page must use HasSavedViews; every
 * write goes through SavedViewService and every action is authorised
 * through SavedViewPolicy.
 */
final class SavedViewActions
{
    /**
     * @return array<Action|ActionGroup>
     */
    public static function for(ListRecords $page): array
    {
        if (! in_array(HasSavedViews::class, class_uses_recursive($page), true)) {
            throw new LogicException(sprintf('%s must use %s to offer saved views.', $page::class, HasSavedViews::class));
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $views = app(SavedViewService::class)->listFor($user, self::resourceKey($page));

        return [
            self::views($page, $user, $views),
            self::save($page, $user),
            self::clear($page, $user),
        ];
    }

    /**
     * @param  Collection<int, SavedView>  $views
     */
    private static function views(ListRecords $page, User $user, Collection $views): ActionGroup
    {
        $mine = [];
        $shared = [];

        foreach ($views as $view) {
            $action = Action::make('view_'.$view->getKey())
                ->label(self::label($view, $user))
                ->icon($view->is_default && $view->isOwnedBy($user) ? Heroicon::OutlinedStar : Heroicon::OutlinedBookmark)
                ->authorize(fn (): bool => $user->can('view', $view))
                ->action(fn () => self::apply($page, $view, $user));

            if ($view->isOwnedBy($user)) {
                $mine[] = $action;
            } else {
                $shared[] = $action;
            }
        }

        $actions = [];

        if ($mine !== []) {
            $actions[] = self::group('mine', __('saved_views.labels.mine'), $mine);
        }

        if ($shared !== []) {
            $actions[] = self::group('shared', __('saved_views.labels.shared'), $shared);
        }

        if ($actions === []) {
            $actions[] = Action::make('empty')
                ->label(__('saved_views.empty'))
                ->disabled();
        }

        $actions[] = self::manage($user, $views);

        return ActionGroup::make($actions)
            ->label(__('saved_views.actions.views'))
            ->icon(Heroicon::OutlinedBookmark)
            ->color('gray')
            ->button();
    }

    /**
     * A divided section of the dropdown: a heading row (not clickable) and
     * the views beneath it.
     *
     * @param  array<Action>  $actions
     */
    private static function group(string $name, string $heading, array $actions): ActionGroup
    {
        return ActionGroup::make([
            Action::make($name.'_heading')
                ->label($heading)
                ->disabled(),
            ...$actions,
        ])->dropdown(false);
    }

    /**
     * @param  Collection<int, SavedView>  $views
     */
    private static function manage(User $user, Collection $views): Action
    {
        $manageable = self::manageable($user, $views);
        $options = $manageable->mapWithKeys(fn (SavedView $view): array => [
            (int) $view->getKey() => $view->isOwnedBy($user)
                ? $view->name
                : __('saved_views.labels.owned_by', ['name' => $view->name, 'owner' => (string) $view->user?->name]),
        ])->all();

        return Action::make('manage')
            ->label(__('saved_views.actions.manage'))
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->color('gray')
            ->modalHeading(__('saved_views.actions.manage_heading'))
            ->modalWidth(Width::Medium)
            ->modalSubmitAction(false)
            ->schema([
                Select::make('saved_view_id')
                    ->label(__('saved_views.fields.view'))
                    ->helperText(__('saved_views.helpers.manage'))
                    ->options($options)
                    ->required()
                    ->live()
                    ->native(false),
            ])
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('setDefault', ['operation' => 'default'])
                    ->label(__('saved_views.actions.set_default'))
                    ->icon(Heroicon::OutlinedStar)
                    ->color('primary')
                    ->visible(fn (): bool => self::selectedView($action, $manageable)?->isOwnedBy($user) ?? false),
                $action->makeModalSubmitAction('delete', ['operation' => 'delete'])
                    ->label(__('saved_views.actions.delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger'),
            ])
            ->authorize(fn (): bool => $user->can('viewAny', SavedView::class))
            ->visible($options !== [])
            ->action(function (array $data, array $arguments) use ($user): void {
                $view = SavedView::query()->find((int) ($data['saved_view_id'] ?? 0));

                if (! $view instanceof SavedView) {
                    return;
                }

                $service = app(SavedViewService::class);

                try {
                    match ($arguments['operation'] ?? null) {
                        'delete' => self::deleteView($service, $view, $user),
                        'default' => self::setDefaultView($service, $view, $user),
                        default => null,
                    };
                } catch (AuthorizationException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                }
            });
    }

    private static function save(ListRecords $page, User $user): Action
    {
        $resource = self::resourceKey($page);

        return Action::make('saveView')
            ->label(__('saved_views.actions.save'))
            ->icon(Heroicon::OutlinedBookmarkSquare)
            ->color('gray')
            ->modalHeading(__('saved_views.actions.save_heading'))
            ->modalSubmitActionLabel(__('saved_views.actions.save_submit'))
            ->modalWidth(Width::Medium)
            ->schema([
                TextInput::make('name')
                    ->label(__('saved_views.fields.name'))
                    ->required()
                    ->maxLength(SavedViewService::NAME_MAX_LENGTH)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($page, $user, $resource): void {
                        $service = app(SavedViewService::class);
                        $errors = $service->stateErrors((string) $value, TableState::fromListPage($page));

                        if ($errors !== []) {
                            $fail((string) reset($errors));

                            return;
                        }

                        if (! $service->isNameAvailable($user, $resource, (string) $value)) {
                            $fail(__('saved_views.validation.name_taken'));
                        }
                    }),

                Toggle::make('is_shared')
                    ->label(__('saved_views.fields.is_shared'))
                    ->helperText(__('saved_views.helpers.is_shared'))
                    ->visible(fn (): bool => $user->can('share', SavedView::class)),

                Toggle::make('is_default')
                    ->label(__('saved_views.fields.is_default'))
                    ->helperText(__('saved_views.helpers.is_default')),
            ])
            ->authorize(fn (): bool => $user->can('create', SavedView::class))
            ->action(function (array $data) use ($page, $user, $resource): void {
                try {
                    $view = app(SavedViewService::class)->save(
                        $user,
                        $resource,
                        (string) ($data['name'] ?? ''),
                        TableState::fromListPage($page),
                        (bool) ($data['is_shared'] ?? false),
                        (bool) ($data['is_default'] ?? false),
                    );
                } catch (AuthorizationException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('saved_views.notifications.saved', ['name' => $view->name]))
                    ->success()
                    ->send();
            });
    }

    private static function clear(ListRecords $page, User $user): Action
    {
        return Action::make('clearView')
            ->label(__('saved_views.actions.clear'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->authorize(fn (): bool => $user->can('viewAny', SavedView::class))
            ->action(fn () => TableState::reset($page));
    }

    /** The stable key views are stored under: the resource's slug (`leads`, `deals`, …). */
    public static function resourceKey(ListRecords $page): string
    {
        return $page::getResource()::getSlug();
    }

    /**
     * Apply a view the user may see to the page and say so.
     */
    public static function apply(ListRecords $page, SavedView $view, User $user): void
    {
        if (! $user->can('view', $view)) {
            return;
        }

        TableState::fromSavedView($view)->applyTo($page);

        Notification::make()
            ->title(__('saved_views.notifications.applied', ['name' => $view->name]))
            ->success()
            ->send();
    }

    private static function deleteView(SavedViewService $service, SavedView $view, User $user): void
    {
        $service->delete($view, $user);

        Notification::make()
            ->title(__('saved_views.notifications.deleted', ['name' => $view->name]))
            ->success()
            ->send();
    }

    private static function setDefaultView(SavedViewService $service, SavedView $view, User $user): void
    {
        $service->setDefault($view, $user);

        Notification::make()
            ->title(__('saved_views.notifications.default_set', ['name' => $view->name]))
            ->success()
            ->send();
    }

    /**
     * The views the user may manage from the modal: their own, plus — for a
     * `users.manage` holder — the shared views of others (delete only).
     *
     * @param  Collection<int, SavedView>  $views
     * @return Collection<int, SavedView>
     */
    private static function manageable(User $user, Collection $views): Collection
    {
        $mayTidyShared = $user->can(Permission::UsersManage->value);

        return $views->filter(fn (SavedView $view): bool => $view->isOwnedBy($user) || ($view->is_shared && $mayTidyShared))->values();
    }

    /**
     * The manageable view currently chosen in the (mounted) manage modal.
     *
     * @param  Collection<int, SavedView>  $manageable
     */
    private static function selectedView(Action $manage, Collection $manageable): ?SavedView
    {
        if ($manage->getLivewire() === null) {
            return null;
        }

        $id = (int) ($manage->getRawData()['saved_view_id'] ?? 0);

        return $manageable->first(fn (SavedView $view): bool => (int) $view->getKey() === $id);
    }

    /**
     * The view's name, marked when it is shared and when it is the actor's
     * default — so an owner can tell which of their views the team sees.
     */
    private static function label(SavedView $view, User $user): string
    {
        $label = $view->name;

        if ($view->is_shared) {
            $label .= ' '.__('saved_views.labels.shared_suffix');
        }

        if ($view->is_default && $view->isOwnedBy($user)) {
            $label .= ' '.__('saved_views.labels.default_suffix');
        }

        return $label;
    }
}
