<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Enums\DealStatus;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only write path for projects (decision D-30), and the read model of
 * the Sales «المشاريع» page.
 *
 * Invariants, each kept inside one transaction with the row locked:
 *
 * 1. Exactly one project is the default at all times — the project a new user
 *    starts in (CurrentProject) and the one every pre-D-30 record was assigned
 *    to. Making a project the default clears the flag on the previous default
 *    in the same transaction; the first project ever created becomes the
 *    default on its own, so the invariant holds from the first row.
 * 2. The default cannot be unset (`projects.validation.last_default`): an
 *    administrator promotes another project instead, which unsets this one.
 * 3. The default must be active (`projects.validation.default_must_be_active`):
 *    neither can the default be deactivated nor an inactive project be made
 *    the default — the switcher and the import select offer active projects
 *    only, and a workspace whose default is hidden would strand every user
 *    who never chose a project.
 * 4. The default and a project still referenced by a lead, contact, account
 *    or deal (soft-deleted ones included — the RESTRICT keys count them) are
 *    never deleted; such a project is deactivated instead. ProjectPolicy
 *    hides the action first; the service refuses the direct call.
 *
 * Every refusal is a ValidationException on the field it concerns, so the
 * resource form shows it beside the toggle that caused it. Switching the
 * current project is CurrentProject's job, not this class's.
 */
final class ProjectService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Project
    {
        return DB::transaction(function () use ($data): Project {
            $wantsDefault = (bool) ($data['is_default'] ?? false);
            $wantsActive = (bool) ($data['is_active'] ?? true);

            if (! $wantsDefault && ! Project::query()->lockForUpdate()->where('is_default', true)->exists()) {
                $wantsDefault = true;
            }

            if ($wantsDefault && ! $wantsActive) {
                throw ValidationException::withMessages(['is_default' => __('projects.validation.default_must_be_active')]);
            }

            if ($wantsDefault) {
                $this->clearDefaults();
            }

            // The flags as resolved, so the row and the instance handed back
            // carry them even when the form left one at the database default.
            // Written on the instance rather than into $data: Larastan checks a
            // create() array's keys against the model's columns and cannot see
            // through an array it has watched grow.
            $project = new Project($data);
            $project->is_default = $wantsDefault;
            $project->is_active = $wantsActive;
            $project->save();

            return $project;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Project $project, array $data): Project
    {
        return DB::transaction(function () use ($project, $data): Project {
            $current = Project::query()->lockForUpdate()->findOrFail($project->getKey());

            $wantsDefault = array_key_exists('is_default', $data) ? (bool) $data['is_default'] : $current->is_default;
            $wantsActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $current->is_active;

            if ($current->is_default && ! $wantsDefault) {
                throw ValidationException::withMessages(['is_default' => __('projects.validation.last_default')]);
            }

            if ($wantsDefault && ! $wantsActive) {
                // The default being switched off, or an inactive project being
                // promoted: the message is the same, the field is the one the
                // administrator just changed.
                $field = $current->is_default ? 'is_active' : 'is_default';

                throw ValidationException::withMessages([$field => __('projects.validation.default_must_be_active')]);
            }

            if ($wantsDefault && ! $current->is_default) {
                $this->clearDefaults($current);
            }

            $current->update($data);

            return $current;
        });
    }

    public function delete(Project $project): void
    {
        DB::transaction(function () use ($project): void {
            $current = Project::query()->lockForUpdate()->findOrFail($project->getKey());

            if ($current->is_default) {
                throw ValidationException::withMessages(['is_default' => __('projects.validation.last_default')]);
            }

            if ($this->isInUse($current)) {
                throw ValidationException::withMessages(['is_active' => __('projects.validation.in_use')]);
            }

            $current->delete();
        });
    }

    /** Whether any lead, contact, account or deal — soft-deleted or not — still belongs to the project. */
    public function isInUse(Project $project): bool
    {
        $id = $project->getKey();

        return Lead::withTrashed()->where('project_id', $id)->exists()
            || Contact::withTrashed()->where('project_id', $id)->exists()
            || Account::withTrashed()->where('project_id', $id)->exists()
            || Deal::withTrashed()->where('project_id', $id)->exists();
    }

    /**
     * The record counts of each project within the viewer's visibility (D-4)
     * and the value of its open deals — the figures of the «المشاريع» cards.
     *
     * Four aggregate queries whatever the number of projects: one per entity,
     * grouped by project, each narrowed by RecordVisibilityResolver exactly
     * as the lists are, so a rep counts their own records and a manager the
     * team's. The deals query also sums the open ones. A project with no
     * visible record is reported with zeros rather than left out.
     *
     * @param  list<int>  $projectIds
     * @return array<int, array{leads_count: int, contacts_count: int, accounts_count: int, deals_count: int, open_deals_count: int, open_deals_amount: float}>
     */
    public function countsFor(User $viewer, array $projectIds): array
    {
        $counts = [];

        foreach ($projectIds as $id) {
            $counts[$id] = [
                'leads_count' => 0,
                'contacts_count' => 0,
                'accounts_count' => 0,
                'deals_count' => 0,
                'open_deals_count' => 0,
                'open_deals_amount' => 0.0,
            ];
        }

        if ($projectIds === []) {
            return $counts;
        }

        $resolver = app(RecordVisibilityResolver::class);

        $byEntity = [
            'leads_count' => $this->countByProject($resolver->visible($viewer, Lead::query()), $projectIds),
            'contacts_count' => $this->countByProject($resolver->visible($viewer, Contact::query()), $projectIds),
            'accounts_count' => $this->countByProject($resolver->visible($viewer, Account::query()), $projectIds),
        ];

        foreach ($byEntity as $key => $byProject) {
            foreach ($byProject as $id => $count) {
                $counts[$id][$key] = $count;
            }
        }

        $open = DealStatus::Open->value;
        $deals = $this->perProject($resolver->visible($viewer, Deal::query()), $projectIds)
            ->selectRaw('COUNT(*) AS deals_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS open_deals_count', [$open])
            ->selectRaw('SUM(CASE WHEN status = ? THEN amount ELSE 0 END) AS open_deals_amount', [$open])
            ->get();

        foreach ($deals as $row) {
            $id = (int) $row->getAttribute('project_id');

            $counts[$id]['deals_count'] = (int) $row->getAttribute('deals_count');
            $counts[$id]['open_deals_count'] = (int) $row->getAttribute('open_deals_count');
            $counts[$id]['open_deals_amount'] = (float) $row->getAttribute('open_deals_amount');
        }

        return $counts;
    }

    /**
     * How many rows of the (already visibility-scoped) query each project holds.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $projectIds
     * @return array<int, int>
     */
    private function countByProject(Builder $query, array $projectIds): array
    {
        $counts = [];

        foreach ($this->perProject($query, $projectIds)->selectRaw('COUNT(*) AS aggregate')->get() as $row) {
            $counts[(int) $row->getAttribute('project_id')] = (int) $row->getAttribute('aggregate');
        }

        return $counts;
    }

    /**
     * The query grouped by project, over the projects asked for.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $projectIds
     * @return Builder<TModel>
     */
    private function perProject(Builder $query, array $projectIds): Builder
    {
        $column = $query->qualifyColumn('project_id');

        return $query
            ->whereIn($column, $projectIds)
            ->groupBy($column)
            ->select($column);
    }

    private function clearDefaults(?Project $except = null): void
    {
        Project::query()
            ->where('is_default', true)
            ->when($except !== null, static fn (Builder $query): Builder => $query->whereKeyNot($except?->getKey()))
            ->lockForUpdate()
            ->get()
            ->each(static fn (Project $other): bool => $other->update(['is_default' => false]));
    }
}
