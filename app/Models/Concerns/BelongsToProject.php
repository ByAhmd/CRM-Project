<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Project;
use App\Models\User;
use App\Services\Projects\CurrentProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record of the customer base that lives in one project (decision D-30):
 * leads, contacts, accounts and deals. `project_id` is required, set when
 * the record is created and never edited afterwards.
 *
 * Who sets it: the importer (the project chosen in the import modal), the
 * lead conversion (the lead's own project), a test (explicitly). Every other
 * creation — the create pages, the merge, anything that builds a record
 * without naming a project — gets the integrity guard below: the project
 * the signed-in user is working in (CurrentProject), or the default project
 * when nobody is signed in (console work). A form never offers the project
 * as a choice; it shows the current one as a badge.
 */
trait BelongsToProject
{
    public static function bootBelongsToProject(): void
    {
        static::creating(static function (Model $model): void {
            if ($model->getAttribute('project_id') !== null) {
                return;
            }

            $user = auth()->user();
            $current = app(CurrentProject::class);

            $model->setAttribute('project_id', ($user instanceof User ? $current->for($user) : $current->default())->getKey());
        });
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * The records of one project.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInProject(Builder $query, Project|int $project): Builder
    {
        $id = $project instanceof Project ? $project->getKey() : $project;

        return $query->where($query->qualifyColumn('project_id'), $id);
    }
}
