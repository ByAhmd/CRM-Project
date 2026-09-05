<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies RecordVisibilityResolver to Filament Resource queries (D-4).
 *
 * Policies guard per-record access; this closes the list / URL / global-search
 * path so out-of-scope rows never appear and cannot be bound by id. Used by
 * every Resource whose model implements OwnedRecord, inside getEloquentQuery()
 * and getGlobalSearchEloquentQuery(). The query's model must implement
 * OwnedRecord; the resolver refuses anything else.
 */
trait ScopesQueriesToVisibleRecords
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected static function constrainToVisible(Builder $query): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return app(RecordVisibilityResolver::class)->visible($user, $query);
    }
}
