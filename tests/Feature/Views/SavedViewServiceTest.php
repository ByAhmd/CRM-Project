<?php

declare(strict_types=1);

namespace Tests\Feature\Views;

use App\Models\SavedView;
use App\Models\User;
use App\Services\Views\SavedViewService;
use App\Services\Views\TableState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * SavedViewService and SavedViewPolicy (decision A-8): ownership, sharing,
 * the single default per owner and resource, unique names and who may read,
 * change or remove a view.
 */
final class SavedViewServiceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
    }

    private function service(): SavedViewService
    {
        return app(SavedViewService::class);
    }

    private static function state(?string $search = 'Zeta'): TableState
    {
        return new TableState(
            filters: ['lead_status_id' => ['values' => [3]], 'priority' => ['values' => ['high']]],
            sortColumn: 'created_at',
            sortDirection: 'desc',
            search: $search,
            columns: ['phone' => true, 'email' => false],
        );
    }

    private function assertDenied(callable $attempt, string $message): void
    {
        try {
            $attempt();
        } catch (AuthorizationException $exception) {
            $this->assertSame($message, $exception->getMessage());

            return;
        }

        $this->fail('Expected an AuthorizationException.');
    }

    #[Test]
    public function save_stores_the_table_state_under_the_owner_and_resource(): void
    {
        $rep = $this->salesRep();

        $view = $this->service()->save($rep, 'leads', '  Hot leads ', self::state());

        $this->assertSame($rep->getKey(), $view->user_id);
        $this->assertSame('leads', $view->resource);
        $this->assertSame('Hot leads', $view->name);
        $this->assertSame(['lead_status_id' => ['values' => [3]], 'priority' => ['values' => ['high']]], $view->filters);
        $this->assertSame('created_at', $view->sort_column);
        $this->assertSame('desc', $view->sort_direction);
        $this->assertSame('Zeta', $view->search);
        $this->assertSame(['phone' => true, 'email' => false], $view->columns);
        $this->assertFalse($view->is_shared);
        $this->assertFalse($view->is_default);
        $this->assertTrue($rep->savedViews()->whereKey($view->getKey())->exists());
    }

    #[Test]
    public function an_empty_state_is_stored_as_nulls_and_restores_to_an_empty_state(): void
    {
        $rep = $this->salesRep();

        $view = $this->service()->save($rep, 'deals', 'Plain', new TableState([], null, null, null, null));

        $this->assertNull($view->filters);
        $this->assertNull($view->sort_column);
        $this->assertNull($view->sort_direction);
        $this->assertNull($view->search);
        $this->assertNull($view->columns);

        $state = TableState::fromSavedView($view->fresh() ?? $view);

        $this->assertSame([], $state->filters);
        $this->assertNull($state->sortColumn);
        $this->assertNull($state->columns);
    }

    #[Test]
    public function listing_shows_own_views_first_then_shared_ones_and_hides_the_private_views_of_others(): void
    {
        $rep = $this->salesRep();
        $manager = $this->salesManager();
        $service = $this->service();

        $zulu = $service->save($rep, 'leads', 'Zulu', self::state());
        $alpha = $service->save($rep, 'leads', 'Alpha', self::state());
        $shared = $service->save($manager, 'leads', 'Beta (team)', self::state(), shared: true);
        $service->save($manager, 'leads', 'Private manager view', self::state());
        $service->save($rep, 'deals', 'Other resource', self::state());

        $this->assertSame(
            [$alpha->getKey(), $zulu->getKey(), $shared->getKey()],
            $service->listFor($rep, 'leads')->modelKeys(),
        );
    }

    #[Test]
    public function a_new_default_clears_the_owners_other_defaults_for_that_resource_only(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $service = $this->service();

        $first = $service->save($rep, 'leads', 'First', self::state(), default: true);
        $dealsDefault = $service->save($rep, 'deals', 'Deals default', self::state(), default: true);
        $othersDefault = $service->save($other, 'leads', 'Theirs', self::state(), default: true);

        $second = $service->save($rep, 'leads', 'Second', self::state(), default: true);

        $this->assertFalse($first->refresh()->is_default);
        $this->assertTrue($second->refresh()->is_default);
        $this->assertTrue($dealsDefault->refresh()->is_default);
        $this->assertTrue($othersDefault->refresh()->is_default);
        $this->assertTrue($service->defaultFor($rep, 'leads')?->is($second));

        $service->setDefault($first, $rep);

        $this->assertTrue($first->refresh()->is_default);
        $this->assertFalse($second->refresh()->is_default);
    }

    #[Test]
    public function there_is_no_default_until_someone_sets_one(): void
    {
        $rep = $this->salesRep();
        $manager = $this->salesManager();

        $this->service()->save($manager, 'leads', 'Private, not default', self::state());
        $this->service()->save($manager, 'leads', 'Private default', self::state(), default: true);
        $this->service()->save($manager, 'deals', 'Shared default elsewhere', self::state(), shared: true, default: true);

        $this->assertNull($this->service()->defaultFor($rep, 'leads'));
    }

    #[Test]
    public function a_shared_default_of_someone_else_never_becomes_another_users_default(): void
    {
        $rep = $this->salesRep();
        $manager = $this->salesManager();
        $service = $this->service();

        $team = $service->save($manager, 'leads', 'Team view', self::state(), shared: true, default: true);

        $before = $service->defaultFor($rep, 'leads');

        $this->assertNull($before);
        $this->assertSame($team->getKey(), $service->defaultFor($manager, 'leads')?->getKey());

        $own = $service->save($rep, 'leads', 'Mine', self::state(), default: true);
        $after = $service->defaultFor($rep, 'leads');

        $this->assertSame($own->getKey(), $after?->getKey());
        $this->assertSame($team->getKey(), $service->defaultFor($manager, 'leads')?->getKey());
    }

    #[Test]
    public function a_state_that_does_not_fit_its_columns_is_rejected_with_a_validation_error(): void
    {
        $rep = $this->salesRep();
        $service = $this->service();

        $cases = [
            'search' => ['Fits', self::state(search: str_repeat('a', SavedViewService::SEARCH_MAX_LENGTH + 1))],
            'sort_column' => ['Fits', new TableState([], str_repeat('c', SavedViewService::SORT_COLUMN_MAX_LENGTH + 1), 'asc', null, null)],
            'name' => [str_repeat('n', SavedViewService::NAME_MAX_LENGTH + 1), self::state()],
        ];

        foreach ($cases as $field => [$name, $state]) {
            try {
                $service->save($rep, 'leads', $name, $state);
                $this->fail('Expected a ValidationException for '.$field.'.');
            } catch (ValidationException $exception) {
                $this->assertSame([__('saved_views.validation.'.$field.'_too_long', ['max' => match ($field) {
                    'search' => SavedViewService::SEARCH_MAX_LENGTH,
                    'sort_column' => SavedViewService::SORT_COLUMN_MAX_LENGTH,
                    default => SavedViewService::NAME_MAX_LENGTH,
                }])], $exception->errors()[$field]);
            }

            $this->assertSame([], array_diff_key($service->stateErrors($name, $state), [$field => true]));
        }

        $this->assertDatabaseCount('saved_views', 0);

        $edge = $service->save($rep, 'leads', str_repeat('n', SavedViewService::NAME_MAX_LENGTH), self::state(search: str_repeat('a', SavedViewService::SEARCH_MAX_LENGTH)));

        $this->assertSame(SavedViewService::SEARCH_MAX_LENGTH, mb_strlen((string) $edge->search));

        try {
            $service->update($edge, $rep, 'Renamed', self::state(search: str_repeat('a', SavedViewService::SEARCH_MAX_LENGTH + 1)), shared: false, default: false);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('search', $exception->errors());
        }

        $this->assertSame(str_repeat('n', SavedViewService::NAME_MAX_LENGTH), $edge->refresh()->name);
    }

    #[Test]
    public function a_duplicate_name_that_slips_past_the_check_surfaces_as_the_same_validation_error(): void
    {
        $rep = $this->salesRep();
        $service = $this->service();

        // A concurrent save lands between the availability check and the insert.
        SavedView::creating(function (SavedView $view): void {
            DB::table('saved_views')->insert([
                'user_id' => $view->user_id,
                'resource' => $view->resource,
                'name' => $view->name,
                'is_shared' => false,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $service->save($rep, 'leads', 'Raced', self::state());
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('saved_views.validation.name_taken')], $exception->errors()['name']);
        }

        $this->assertDatabaseCount('saved_views', 0);
    }

    #[Test]
    public function sharing_requires_the_share_permission(): void
    {
        $rep = $this->salesRep();
        $manager = $this->salesManager();

        $this->assertDenied(
            fn () => $this->service()->save($rep, 'leads', 'Team', self::state(), shared: true),
            __('saved_views.validation.share_forbidden'),
        );
        $this->assertDatabaseCount('saved_views', 0);

        $view = $this->service()->save($manager, 'leads', 'Team', self::state(), shared: true);

        $this->assertTrue($view->is_shared);

        $private = $this->service()->save($rep, 'leads', 'Mine', self::state());

        $this->assertDenied(
            fn () => $this->service()->update($private, $rep, 'Mine', self::state(), shared: true, default: false),
            __('saved_views.validation.share_forbidden'),
        );
        $this->assertFalse($private->refresh()->is_shared);
    }

    #[Test]
    public function a_name_is_unique_per_owner_and_resource(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $service = $this->service();

        $service->save($rep, 'leads', 'Hot', self::state());

        try {
            $service->save($rep, 'leads', ' hot ', self::state());
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('saved_views.validation.name_taken')], $exception->errors()['name']);
        }

        $this->assertFalse($service->isNameAvailable($rep, 'leads', 'Hot'));
        $this->assertFalse($service->isNameAvailable($rep, 'leads', '   '));
        $this->assertTrue($service->isNameAvailable($rep, 'deals', 'Hot'));
        $this->assertTrue($service->isNameAvailable($other, 'leads', 'Hot'));

        $service->save($rep, 'deals', 'Hot', self::state());
        $service->save($other, 'leads', 'Hot', self::state());

        $this->assertDatabaseCount('saved_views', 3);
    }

    #[Test]
    public function update_renames_and_restates_a_view_and_keeps_the_name_rule(): void
    {
        $manager = $this->salesManager();
        $service = $this->service();

        $view = $service->save($manager, 'leads', 'Draft', self::state());
        $service->save($manager, 'leads', 'Taken', self::state());

        $updated = $service->update($view, $manager, 'Final', self::state(search: 'Omega'), shared: true, default: true);

        $this->assertSame('Final', $updated->name);
        $this->assertSame('Omega', $updated->search);
        $this->assertTrue($updated->is_shared);
        $this->assertTrue($updated->is_default);

        try {
            $service->update($view, $manager, 'Taken', self::state(), shared: false, default: false);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }

        $this->assertTrue($service->isNameAvailable($manager, 'leads', 'Final', $view));
    }

    #[Test]
    public function only_the_owner_updates_a_view_even_when_it_is_shared(): void
    {
        $manager = $this->salesManager();
        $admin = $this->admin();
        $shared = $this->service()->save($manager, 'leads', 'Team', self::state(), shared: true);

        $this->assertDenied(
            fn () => $this->service()->update($shared, $admin, 'Renamed', self::state(), shared: true, default: false),
            __('saved_views.validation.update_forbidden'),
        );
        $this->assertDenied(
            fn () => $this->service()->setDefault($shared, $admin),
            __('saved_views.validation.update_forbidden'),
        );
        $this->assertSame('Team', $shared->refresh()->name);
    }

    #[Test]
    public function owners_delete_their_own_views_and_user_managers_delete_the_shared_views_of_others(): void
    {
        $rep = $this->salesRep();
        $otherRep = $this->salesRep();
        $manager = $this->salesManager();
        $admin = $this->admin();
        $service = $this->service();

        $own = $service->save($rep, 'leads', 'Mine', self::state());
        $private = $service->save($rep, 'leads', 'Private', self::state());
        $shared = $service->save($manager, 'leads', 'Team', self::state(), shared: true);

        $this->assertDenied(fn () => $service->delete($own, $otherRep), __('saved_views.validation.delete_forbidden'));
        $this->assertDenied(fn () => $service->delete($shared, $rep), __('saved_views.validation.delete_forbidden'));
        $this->assertDenied(fn () => $service->delete($private, $admin), __('saved_views.validation.delete_forbidden'));

        $service->delete($own, $rep);
        $service->delete($shared, $admin);

        $this->assertDatabaseMissing('saved_views', ['id' => $own->getKey()]);
        $this->assertDatabaseMissing('saved_views', ['id' => $shared->getKey()]);
        $this->assertDatabaseHas('saved_views', ['id' => $private->getKey()]);
    }

    #[Test]
    public function the_policy_grants_view_to_owner_or_shared_update_to_owner_and_share_by_permission(): void
    {
        $rep = $this->salesRep();
        $otherRep = $this->salesRep();
        $manager = $this->salesManager();
        $admin = $this->admin();
        $readOnly = $this->readOnly();

        $private = SavedView::factory()->create(['user_id' => $rep->getKey()]);
        $shared = SavedView::factory()->shared()->create(['user_id' => $manager->getKey()]);

        $this->assertTrue($rep->can('view', $private));
        $this->assertFalse($otherRep->can('view', $private));
        $this->assertFalse($admin->can('view', $private));
        $this->assertTrue($rep->can('view', $shared));
        $this->assertTrue($readOnly->can('view', $shared));

        $this->assertTrue($rep->can('update', $private));
        $this->assertFalse($admin->can('update', $private));
        $this->assertFalse($admin->can('update', $shared));

        $this->assertTrue($rep->can('delete', $private));
        $this->assertFalse($otherRep->can('delete', $private));
        $this->assertFalse($admin->can('delete', $private));
        $this->assertTrue($admin->can('delete', $shared));
        $this->assertFalse($rep->can('delete', $shared));

        foreach ([$rep, $readOnly, User::factory()->create()] as $user) {
            $this->assertFalse($user->can('share', SavedView::class));
        }

        foreach ([$manager, $admin, $this->superAdmin()] as $user) {
            $this->assertTrue($user->can('share', SavedView::class));
            $this->assertTrue($user->can('create', SavedView::class));
        }
    }

    #[Test]
    public function views_go_with_their_owner(): void
    {
        $rep = $this->salesRep();
        $view = $this->service()->save($rep, 'leads', 'Mine', self::state());

        $rep->forceDelete();

        $this->assertDatabaseMissing('saved_views', ['id' => $view->getKey()]);
    }
}
