<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Throwable;

/**
 * The table walk the usability probes share: every registered resource's
 * listing plus every relation manager, exactly as $actor sees them. Extracted
 * so the mobile-column-budget and row-action-menu tests read the SAME set of
 * tables — two private copies had already begun to drift when this trait was
 * made, which is the drift CLAUDE.md's "never copy a helper into a test
 * class" rule exists to prevent.
 */
trait WalksPanelTables
{
    /**
     * Every table of the panel as $actor sees it: the listing of each
     * registered resource and each relation manager those resources declare.
     *
     * @return array<string, Table>
     */
    private function tables(User $actor): array
    {
        $tables = $this->listTables($actor);

        // The scoped resource queries below and the column and action closures
        // the tests read afterwards all consult the authenticated user.
        $this->actingAs($actor);

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $relations = $resource::getRelations();

            if ($relations === []) {
                continue;
            }

            $pages = $resource::getPages();
            $registration = $pages['view'] ?? $pages['edit'] ?? null;
            $owner = $this->ownerRecordFor($resource);

            if (! $registration instanceof PageRegistration || ! $owner instanceof Model) {
                continue;
            }

            foreach ($relations as $manager) {
                if (! is_string($manager)) {
                    continue;
                }

                $component = Livewire::actingAs($actor)
                    ->test($manager, ['ownerRecord' => $owner, 'pageClass' => $registration->getPage()])
                    ->instance();

                if ($component instanceof HasTable) {
                    $tables[$manager] = $component->getTable();
                }
            }
        }

        return $tables;
    }

    /**
     * The listing table of every registered resource $actor may open. A page
     * the actor's role keeps from them is left out rather than failing the
     * walk: the counts each test asserts catch a walk that fell silent.
     *
     * @return array<string, Table>
     */
    private function listTables(User $actor): array
    {
        $this->actingAs($actor);

        $tables = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $registration = $resource::getPages()['index'] ?? null;

            if (! $registration instanceof PageRegistration) {
                continue;
            }

            $page = $registration->getPage();

            try {
                $component = Livewire::actingAs($actor)->test($page)->instance();
            } catch (Throwable) {
                continue;
            }

            if ($component instanceof HasTable) {
                $tables[$page] = $component->getTable();
            }
        }

        return $tables;
    }

    /**
     * The record a relation manager hangs off: an existing one when the
     * lookups already seeded it, otherwise a made one.
     *
     * @param  class-string<resource>  $resource
     */
    private function ownerRecordFor(string $resource): ?Model
    {
        $existing = $resource::getEloquentQuery()->first();

        if ($existing instanceof Model) {
            return $existing;
        }

        /** @var class-string<Model> $model */
        $model = $resource::getModel();

        if (! method_exists($model, 'factory')) {
            return null;
        }

        try {
            $model::factory()->create();
        } catch (Throwable) {
            return null;
        }

        return $resource::getEloquentQuery()->first();
    }
}
