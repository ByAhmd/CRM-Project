<?php

declare(strict_types=1);

namespace App\Services\Views;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The only way saved views are written (decision A-8).
 *
 * - a name is unique per owner and resource (checked first; when two saves
 *   race, the unique index has the last word and its violation surfaces as
 *   the same translated validation error);
 * - the stored state must fit its columns: name and sort column up to 100,
 *   search up to 255 characters — a validation error, never a database one;
 * - sharing needs `saved_view.share` (the policy's `share` ability);
 * - one default per owner and resource: marking a view default clears the
 *   owner's other defaults for that resource in the same transaction. A
 *   default is strictly personal: a shared view marked default opens its
 *   owner's list only, never a colleague's (D-4 — another user's preference
 *   never changes what a rep sees);
 * - updating and deleting go through SavedViewPolicy (own view; a
 *   `users.manage` holder may also remove someone else's shared view).
 *
 * Saved views are personal preference data, not business records, so no
 * audit event is written for them (contrast the AuditLogger domains): they
 * carry no customer data, only filter settings, and a user may create and
 * discard them freely.
 */
final class SavedViewService
{
    public const int NAME_MAX_LENGTH = 100;

    public const int SORT_COLUMN_MAX_LENGTH = 100;

    public const int SEARCH_MAX_LENGTH = 255;

    public function save(User $owner, string $resource, string $name, TableState $state, bool $shared = false, bool $default = false): SavedView
    {
        $name = $this->normaliseName($name);

        $this->validate($name, $state);

        if (! $this->isNameAvailable($owner, $resource, $name)) {
            throw ValidationException::withMessages(['name' => __('saved_views.validation.name_taken')]);
        }

        if ($shared) {
            $this->assertMayShare($owner);
        }

        return $this->guardingUniqueName(fn (): SavedView => DB::transaction(function () use ($owner, $resource, $name, $state, $shared, $default): SavedView {
            $view = new SavedView($this->attributes($name, $state, $shared, $default));
            $view->user_id = $owner->getKey();
            $view->resource = $resource;
            $view->save();

            if ($default) {
                $this->clearOtherDefaults($view);
            }

            return $view;
        }));
    }

    public function update(SavedView $view, User $actor, string $name, TableState $state, bool $shared, bool $default): SavedView
    {
        $this->authorise($actor, 'update', $view);

        $name = $this->normaliseName($name);

        $this->validate($name, $state);

        if (! $this->isNameAvailable($view->user, $view->resource, $name, $view)) {
            throw ValidationException::withMessages(['name' => __('saved_views.validation.name_taken')]);
        }

        if ($shared && ! $view->is_shared) {
            $this->assertMayShare($actor);
        }

        return $this->guardingUniqueName(fn (): SavedView => DB::transaction(function () use ($view, $name, $state, $shared, $default): SavedView {
            $view->fill($this->attributes($name, $state, $shared, $default))->save();

            if ($default) {
                $this->clearOtherDefaults($view);
            }

            return $view;
        }));
    }

    public function setDefault(SavedView $view, User $actor): SavedView
    {
        $this->authorise($actor, 'update', $view);

        return DB::transaction(function () use ($view): SavedView {
            $view->is_default = true;
            $view->save();

            $this->clearOtherDefaults($view);

            return $view;
        });
    }

    public function delete(SavedView $view, User $actor): void
    {
        $this->authorise($actor, 'delete', $view);

        DB::transaction(function () use ($view): void {
            $view->delete();
        });
    }

    /**
     * The view a user's list opens with: their own default for that resource,
     * or nothing. Defaults set by other users — shared or not — are theirs.
     */
    public function defaultFor(User $user, string $resource): ?SavedView
    {
        return SavedView::query()
            ->forResource($resource)
            ->where('user_id', $user->getKey())
            ->where('is_default', true)
            ->first();
    }

    /**
     * Own views first, then the shared ones, each group by name.
     *
     * @return Collection<int, SavedView>
     */
    public function listFor(User $user, string $resource): Collection
    {
        return SavedView::query()
            ->with('user')
            ->forResource($resource)
            ->visibleTo($user)
            ->orderByRaw('CASE WHEN saved_views.user_id = ? THEN 0 ELSE 1 END', [$user->getKey()])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function isNameAvailable(User $owner, string $resource, string $name, ?SavedView $ignore = null): bool
    {
        $name = $this->normaliseName($name);

        if ($name === '') {
            return false;
        }

        return ! SavedView::query()
            ->forResource($resource)
            ->where('user_id', $owner->getKey())
            ->where('name', $name)
            ->when($ignore !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignore?->getKey()))
            ->exists();
    }

    /**
     * The validation messages a name and state would fail with, keyed by
     * field — empty when they fit their columns. The save form runs the same
     * check before it submits.
     *
     * @return array<string, string>
     */
    public function stateErrors(string $name, TableState $state): array
    {
        $errors = [];

        if (mb_strlen($this->normaliseName($name)) > self::NAME_MAX_LENGTH) {
            $errors['name'] = __('saved_views.validation.name_too_long', ['max' => self::NAME_MAX_LENGTH]);
        }

        if ($state->search !== null && mb_strlen($state->search) > self::SEARCH_MAX_LENGTH) {
            $errors['search'] = __('saved_views.validation.search_too_long', ['max' => self::SEARCH_MAX_LENGTH]);
        }

        if ($state->sortColumn !== null && mb_strlen($state->sortColumn) > self::SORT_COLUMN_MAX_LENGTH) {
            $errors['sort_column'] = __('saved_views.validation.sort_column_too_long', ['max' => self::SORT_COLUMN_MAX_LENGTH]);
        }

        return $errors;
    }

    private function validate(string $name, TableState $state): void
    {
        $errors = $this->stateErrors($name, $state);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A concurrent save with the same name slips past isNameAvailable() and
     * hits the unique index instead; report it as the check would have.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $write
     * @return TReturn
     */
    private function guardingUniqueName(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('saved_views.validation.name_taken')]);
        }
    }

    private function normaliseName(string $name): string
    {
        return trim($name);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(string $name, TableState $state, bool $shared, bool $default): array
    {
        return [
            'name' => $name,
            'filters' => $state->filters === [] ? null : $state->filters,
            'sort_column' => $state->sortColumn,
            'sort_direction' => $state->sortColumn === null ? null : ($state->sortDirection === 'desc' ? 'desc' : 'asc'),
            'search' => $state->search,
            'columns' => $state->columns,
            'is_shared' => $shared,
            'is_default' => $default,
        ];
    }

    private function clearOtherDefaults(SavedView $view): void
    {
        SavedView::query()
            ->forResource($view->resource)
            ->where('user_id', $view->user_id)
            ->whereKeyNot($view->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    private function assertMayShare(User $user): void
    {
        if (! Gate::forUser($user)->allows('share', SavedView::class)) {
            throw new AuthorizationException(__('saved_views.validation.share_forbidden'));
        }
    }

    private function authorise(User $actor, string $ability, SavedView $view): void
    {
        if (! Gate::forUser($actor)->allows($ability, $view)) {
            throw new AuthorizationException(__('saved_views.validation.'.($ability === 'delete' ? 'delete_forbidden' : 'update_forbidden')));
        }
    }
}
