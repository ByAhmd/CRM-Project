<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\Views\SavedViewService;
use App\Services\Views\TableState;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Saved views on a resource list page (decision A-8).
 *
 * The page keeps its resource slug as the view key and applies the user's
 * own default view the first time the list opens in a session; the apply,
 * save, manage and clear operations themselves are the header actions built
 * by SavedViewActions::for($this). A view only ever rewrites the table's
 * filter, sort, search and column state; the scoped base query of the
 * resource (D-4) is untouched, so a view can narrow the list but never widen
 * it.
 *
 * Livewire runs `bootedInteractsWithTable` (which reads the persisted table
 * state from the session, and then writes it back) before `bootedHasSavedViews`
 * because the table trait belongs to the parent class; the "was there state
 * already?" check therefore happens at mount time, before Filament touches
 * the session, and the default view is applied once the table exists.
 *
 * A default view that the current table can no longer apply (TableState drops
 * what it can, but a filter may still refuse a stale state) must never keep
 * the list from opening: the failure is logged, the table state is reset to
 * its defaults and the page renders as if no default existed.
 */
trait HasSavedViews
{
    protected bool $shouldApplyDefaultSavedView = false;

    /**
     * Decide before the table boots: nothing persisted for this list in the
     * session yet, and nothing supplied through the query string.
     */
    public function mountHasSavedViews(): void
    {
        $this->shouldApplyDefaultSavedView = ! session()->has($this->getTableFiltersSessionKey())
            && ! session()->has($this->getTableSortSessionKey())
            && ! session()->has($this->getTableSearchSessionKey())
            && blank($this->tableFilters)
            && blank($this->tableSort)
            && blank($this->tableSearch);
    }

    public function bootedHasSavedViews(): void
    {
        if (! $this->shouldApplyDefaultSavedView) {
            return;
        }

        $this->shouldApplyDefaultSavedView = false;

        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $view = app(SavedViewService::class)->defaultFor($user, static::savedViewResourceKey());

        if ($view === null) {
            return;
        }

        try {
            TableState::fromSavedView($view)->applyTo($this);
        } catch (Throwable $exception) {
            Log::warning('Default saved view could not be applied; the list opens with its defaults.', [
                'saved_view_id' => $view->getKey(),
                'resource' => $view->resource,
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            TableState::reset($this);
        }
    }

    /** The stable key views are stored under: the resource's slug (`leads`, `deals`, …). */
    public static function savedViewResourceKey(): string
    {
        return static::getResource()::getSlug();
    }
}
