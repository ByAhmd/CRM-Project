<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Exceptions\Projects\InactiveProjectException;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The project a user is working in (decision D-30), and the one scope every
 * project-partitioned query of the Sales workspace passes through.
 *
 * Resolution: the project the user chose last (`users.current_project_id`)
 * while it is still active, otherwise the default project — there is always
 * exactly one (ProjectService keeps it so; the release created it). A user
 * who never chose therefore works in the default project, and one whose
 * project was deactivated falls back to it without an error.
 *
 * Bound as a scoped singleton (AppServiceProvider): the answer for a user is
 * computed once per request or queued job and reused by every list, widget
 * and select that asks, so the scope costs the page two queries, not two per
 * table. switch() updates the memo as it writes.
 *
 * Switching is a preference, written here only: not audited, no
 * notification. The scope is `project_id = current`, applied by
 * Filament\Concerns\ScopesQueriesToCurrentProject to the four resources'
 * list and global-search queries, by the deal board, the sales dashboard,
 * the commercial reports (unless they ask for every project) and the
 * exports; a record page is never scoped (a link opens whatever project
 * the record is in, D-30).
 */
final class CurrentProject
{
    /** @var array<int, Project> */
    private array $resolved = [];

    /** The project the user works in right now. */
    public function for(User $user): Project
    {
        $key = (int) $user->getKey();

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $chosenId = $this->chosenProjectId($user);
        $chosen = $chosenId === null ? null : Project::query()->whereKey($chosenId)->first();

        return $this->resolved[$key] = ($chosen instanceof Project && $chosen->is_active) ? $chosen : $this->default();
    }

    /**
     * Makes the project the user's current one. An inactive project is
     * refused: the switcher and the import select never offer one, so a
     * stale id is the only way here.
     */
    public function switch(User $user, Project $project): void
    {
        if (! $project->is_active) {
            throw InactiveProjectException::for($project);
        }

        DB::transaction(static function () use ($user, $project): void {
            $user->forceFill(['current_project_id' => $project->getKey()])->save();
        });

        $user->setRelation('currentProject', $project);
        $this->resolved[(int) $user->getKey()] = $project;
    }

    /**
     * The default project: the one flagged, or — should the flag ever be
     * missing — the first active project by order, so the workspace never
     * has no project to show.
     */
    public function default(): Project
    {
        return Project::query()->where('is_default', true)->orderBy('id')->first()
            ?? Project::query()->active()->firstOrFail();
    }

    /**
     * The query narrowed to the user's current project. The query's model
     * must carry `project_id` (Models\Concerns\BelongsToProject).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(User $user, Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('project_id'), $this->for($user)->getKey());
    }

    /**
     * The id the user chose, read from the row when the instance in hand does
     * not carry the column — a user built in memory by a factory or a form
     * has only the attributes it was given, and the strict attribute mode
     * refuses a guess.
     */
    private function chosenProjectId(User $user): ?int
    {
        $attributes = $user->getAttributes();

        $value = array_key_exists('current_project_id', $attributes)
            ? $attributes['current_project_id']
            : User::query()->whereKey($user->getKey())->value('current_project_id');

        return $value === null ? null : (int) $value;
    }
}
