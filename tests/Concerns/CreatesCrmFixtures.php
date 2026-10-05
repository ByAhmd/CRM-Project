<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\ActivityKind;
use App\Enums\CloseReasonKind;
use App\Enums\CrmRole;
use App\Enums\LeadStatusKind;
use App\Enums\Permission;
use App\Enums\StageKind;
use App\Enums\TaskStatus;
use App\Enums\TaskUpdateKind;
use App\Enums\WorkspacePanel;
use App\Models\ActivityType;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\Import;
use App\Models\LeadStatus;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskUpdate;
use App\Models\Team;
use App\Models\User;
use App\Support\Filament\WorkspacePanelRegistry;
use Carbon\CarbonInterface;
use Database\Seeders\ActivityTypeSeeder;
use Database\Seeders\DealCloseReasonSeeder;
use Database\Seeders\IndustrySeeder;
use Database\Seeders\LeadSourceSeeder;
use Database\Seeders\LeadStatusSeeder;
use Database\Seeders\PipelineSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Actions\Imports\Models\FailedImportRow;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Testing\TestResponse;
use League\Csv\Reader;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

use function Livewire\before;

/**
 * Fixture helpers shared by feature tests (CLAUDE.md section 3: fixtures come
 * from this trait, never from copies in the test classes).
 *
 * Permissions are cached in the array store for the whole test process while
 * the database is rolled back between tests, so every seed and every role or
 * permission sync also flushes the spatie cache; otherwise a later test would
 * read permission ids from a transaction that no longer exists.
 */
trait CreatesCrmFixtures
{
    protected function seedAccess(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** The production lookup defaults (statuses, sources, pipeline, …). */
    protected function seedLookups(): void
    {
        $this->seed([
            LeadSourceSeeder::class,
            LeadStatusSeeder::class,
            IndustrySeeder::class,
            PipelineSeeder::class,
            ActivityTypeSeeder::class,
            DealCloseReasonSeeder::class,
        ]);
    }

    /**
     * Makes Filament's panel context follow the component under test (D-23).
     *
     * The home panel (the login host and default panel) becomes current, and
     * every Livewire component mounted or hydrated from then on — through
     * Livewire::test(), or nested inside one — switches the current panel to
     * the panel that registers it: a resource page or relation manager to its
     * resource's panel, a page or widget to the panel that lists it
     * (WorkspacePanelRegistry). Anything no panel lists (the auth pages, the
     * top bar, the record timeline) leaves the current panel alone. Laravel
     * builds a fresh application per test, so the hooks never outlive it.
     */
    protected function usePanel(): void
    {
        Filament::setCurrentPanel(WorkspacePanel::HOME);

        before('mount', static function (Component $component, mixed $params = []): void {
            $panelId = self::owningPanelOfComponent($component, is_array($params) ? $params : []);

            if ($panelId !== null) {
                Filament::setCurrentPanel($panelId);
            }
        });

        before('hydrate', static function (Component $component): void {
            $panelId = self::owningPanelOfComponent($component, []);

            if ($panelId !== null) {
                Filament::setCurrentPanel($panelId);
            }
        });
    }

    /**
     * The id of the panel that serves a Livewire component; null for one no
     * panel registers.
     *
     * @param  array<mixed>  $params
     */
    private static function owningPanelOfComponent(Component $component, array $params): ?string
    {
        if ($component instanceof ResourcePage) {
            return WorkspacePanelRegistry::owningPanelId($component::getResource());
        }

        if ($component instanceof RelationManager) {
            $pageClass = $params['pageClass'] ?? $component->pageClass;

            if (is_string($pageClass) && is_subclass_of($pageClass, ResourcePage::class)) {
                return WorkspacePanelRegistry::owningPanelId($pageClass::getResource());
            }

            foreach (WorkspacePanelRegistry::panelIds() as $panelId) {
                foreach (WorkspacePanelRegistry::resources($panelId) as $resource) {
                    if (in_array($component::class, $resource::getRelations(), true)) {
                        return $panelId;
                    }
                }
            }

            return null;
        }

        return WorkspacePanelRegistry::owningPanelId($component::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeUser(CrmRole $role, array $attributes = [], ?Team $team = null): User
    {
        $user = User::factory()->create($attributes + ['team_id' => $team?->getKey()]);
        $user->syncRoles([$role->value]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh() ?? $user;
    }

    /**
     * A user holding exactly the given permissions and nothing through a role
     * (buildable through the Roles resource, D-3), optionally in a team.
     *
     * @param  list<Permission>  $permissions
     */
    protected function userWithPermissions(?Team $team, array $permissions): User
    {
        $user = User::factory()->create(['team_id' => $team?->getKey()]);
        $user->syncRoles([]);
        $user->syncPermissions(array_map(static fn (Permission $permission): string => $permission->value, $permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh() ?? $user;
    }

    protected function superAdmin(?Team $team = null): User
    {
        return $this->makeUser(CrmRole::SuperAdmin, ['name' => 'Super Admin'], $team);
    }

    protected function admin(?Team $team = null): User
    {
        return $this->makeUser(CrmRole::Admin, ['name' => 'Admin User'], $team);
    }

    protected function salesManager(?Team $team = null): User
    {
        return $this->makeUser(CrmRole::SalesManager, ['name' => 'Sales Manager'], $team);
    }

    protected function salesRep(?Team $team = null): User
    {
        return $this->makeUser(CrmRole::SalesRep, ['name' => 'Sales Rep'], $team);
    }

    protected function support(?Team $team = null): User
    {
        return $this->makeUser(CrmRole::Support, ['name' => 'Support User'], $team);
    }

    protected function readOnly(?Team $team = null): User
    {
        return $this->makeUser(CrmRole::ReadOnly, ['name' => 'Read Only User'], $team);
    }

    protected function employee(?Team $team = null): User
    {
        return $this->makeUser(CrmRole::Employee, ['name' => 'Employee User'], $team);
    }

    protected function makeTeam(string $nameEn = 'Riyadh Team', string $nameAr = 'فريق الرياض', ?User $manager = null): Team
    {
        return Team::factory()->create([
            'name_en' => $nameEn,
            'name_ar' => $nameAr,
            'manager_user_id' => $manager?->getKey(),
        ]);
    }

    /**
     * A task assigned to the user in the given state, written straight to the
     * table the way TaskFactory's closed states are (the workflow guard
     * watches updates, not inserts), so a read model can be pinned to fixed
     * moments (D-26). `assigned_at` is stamped by the observer at the current
     * (travelled) time; `created_at`, `updated_at`, `due_at` and
     * `completed_at` may be given.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function taskAssignedTo(User $assignee, TaskStatus $status, array $attributes = []): Task
    {
        return Task::factory()->create([
            'assignee_id' => $assignee->getKey(),
            'created_by' => $assignee->getKey(),
            'status' => $status,
            ...$attributes,
        ]);
    }

    /** A thread entry (progress or comment) on the task at a fixed moment, as TaskService writes it. */
    protected function threadEntryOn(Task $task, User $author, CarbonInterface $at, TaskUpdateKind $kind = TaskUpdateKind::Comment, string $body = 'Entry', ?TaskStatus $status = null): TaskUpdate
    {
        $entry = new TaskUpdate;
        $entry->forceFill([
            'task_id' => $task->getKey(),
            'user_id' => $author->getKey(),
            'kind' => $kind,
            'status' => $status,
            'body' => $body,
            'created_at' => $at,
        ])->save();

        return $entry;
    }

    /** A checklist item on the task, ticked at the given moment or left open. */
    protected function checklistItemOn(Task $task, string $body, ?CarbonInterface $tickedAt = null): TaskChecklistItem
    {
        $item = new TaskChecklistItem;
        $item->forceFill([
            'task_id' => $task->getKey(),
            'body' => $body,
            'position' => TaskChecklistItem::query()->where('task_id', $task->getKey())->count(),
            'completed_at' => $tickedAt,
            'completed_by' => $tickedAt === null ? null : $task->assignee_id,
        ])->save();

        return $item;
    }

    /** The first active seeded lead status of a kind, in status order (needs seedLookups()). */
    protected function statusOfKind(LeadStatusKind $kind): LeadStatus
    {
        return LeadStatus::query()->where('kind', $kind->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
    }

    /** The first active seeded close reason of a kind, in reason order (needs seedLookups()). */
    protected function reasonOfKind(CloseReasonKind $kind): DealCloseReason
    {
        return DealCloseReason::query()->where('kind', $kind->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
    }

    /** The system activity type of a kind — one is seeded per kind (needs seedLookups()). */
    protected function typeOfKind(ActivityKind $kind): ActivityType
    {
        return ActivityType::query()->where('kind', $kind->value)->where('is_system', true)->firstOrFail();
    }

    /** The n-th stage of a kind in the deal's pipeline, in pipeline order. */
    protected function stageOfKind(Deal $deal, StageKind $kind, int $position = 0): PipelineStage
    {
        return PipelineStage::query()
            ->where('pipeline_id', $deal->pipeline_id)
            ->where('kind', $kind->value)
            ->orderBy('sort')
            ->orderBy('id')
            ->skip($position)
            ->firstOrFail();
    }

    /** The n-th Open stage of the deal's pipeline, in pipeline order (0 = the default stage). */
    protected function openStage(Deal $deal, int $position): PipelineStage
    {
        return $this->stageOfKind($deal, StageKind::Open, $position);
    }

    /**
     * A table column-manager state with the named column toggled on, ready
     * for `applyTableColumnManager` (columns hidden by default, such as
     * custom-field columns).
     *
     * @param  array<int, array<string, mixed>>  $state
     * @return array<int, array<string, mixed>>
     */
    protected function columnStateWith(array $state, string $name): array
    {
        foreach ($state as $index => $item) {
            if (($item['name'] ?? null) === $name) {
                $state[$index]['isToggled'] = true;
            }
        }

        return $state;
    }

    /**
     * The rows of a CSV under tests/Fixtures/imports, keyed by header.
     *
     * @return list<array<string, string>>
     */
    protected function importFixtureRows(string $fixture): array
    {
        $reader = Reader::createFromPath(base_path('tests/Fixtures/imports/'.$fixture));
        $reader->setHeaderOffset(0);

        return array_values(iterator_to_array($reader->getRecords()));
    }

    /** The reason the import refused the row whose $column holds $value; fails the test when no such row failed. */
    protected function failedImportReason(Import $import, string $column, string $value): ?string
    {
        foreach (FailedImportRow::query()->where('import_id', $import->getKey())->get() as $row) {
            if (($row->data[$column] ?? null) === $value) {
                return $row->validation_error;
            }
        }

        $this->fail("no failed row with {$column} = {$value}");
    }

    /**
     * Pins a URL a test computed to the workspace that serves it (D-23), so a
     * negative assertion over rendered HTML ("this link is absent") cannot
     * pass merely because the test built the address in another panel than
     * the one the application renders.
     */
    protected function assertUrlInWorkspace(WorkspacePanel $workspace, string $url): void
    {
        $this->assertStringStartsWith(
            rtrim(url($workspace->path()), '/').'/',
            $url,
            "{$url} is not an address of the {$workspace->id()} workspace",
        );
    }

    /**
     * Loads a panel page as the user over HTTP and returns the first
     * Livewire snapshot it rendered, exactly as the browser holds it — the
     * starting point for replaying update requests from an open page.
     */
    protected function firstLivewireSnapshot(User $user, string $url): string
    {
        $html = (string) $this->actingAs($user)->get($url)->assertOk()->getContent();

        $this->assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', $html, $match), 'The page rendered no Livewire snapshot.');

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    /**
     * One update request from an open page. Livewire remembers, per request
     * cycle, which routes it already ran persistent middleware for; a real
     * browser request starts a fresh cycle, so the state is flushed first to
     * make each call behave like its own request.
     *
     * @return TestResponse<Response>
     */
    protected function livewireUpdate(string $snapshot): TestResponse
    {
        Livewire::flushState();

        return $this->withHeaders(['X-Livewire' => 'true'])->postJson(app(HandleRequests::class)->getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
        ]);
    }
}
