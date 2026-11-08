<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\Projects\CurrentProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies the current project to the Sales screens of a Resource (decision
 * D-30): the list table (and so the export launched from it), the global
 * search and the deal board show the project the user is working in and
 * nothing else.
 *
 * Never inside getEloquentQuery(). That query is the Resource's readable set
 * (ScopesQueriesToVisibleRecords, D-4) and everything that follows a record
 * rather than a list reads it: the route binding of the record pages, the
 * relation managers on a record page, the task and activity readers
 * (TaskResource / ActivityResource::constrainToReadable), the subject pickers
 * of the Tasks workspace and the duplicate and conversion services. Scoping it
 * there would hide a task about a record of another project, 404 a link to it
 * and empty the tabs of a record opened from another project — all of which
 * D-30 forbids (a record opens from any link whatever the current project; the
 * Tasks workspace's pickers are not project-scoped). So the scope is applied
 * where the list is built: `->modifyQueryUsing()` on the Resource's table,
 * getGlobalSearchEloquentQuery(), and the board's own queries.
 */
trait ScopesQueriesToCurrentProject
{
    /**
     * The query narrowed to the signed-in user's current project; empty
     * without a user. The query's model must carry `project_id`
     * (Models\Concerns\BelongsToProject).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function constrainToCurrentProject(Builder $query): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return app(CurrentProject::class)->scope($user, $query);
    }
}
