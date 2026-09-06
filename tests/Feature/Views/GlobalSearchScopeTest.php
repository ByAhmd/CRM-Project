<?php

declare(strict_types=1);

namespace Tests\Feature\Views;

use App\Enums\TaskStatus;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Global search (decision A-8) follows the same scope as the lists (D-4):
 * every searchable resource answers through its scoped query, and a user
 * without `<entity>.view_any` is not offered the resource at all.
 */
final class GlobalSearchScopeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    /**
     * @return list<class-string<\Filament\Resources\Resource>>
     */
    private static function resources(): array
    {
        return [LeadResource::class, ContactResource::class, AccountResource::class, DealResource::class, TaskResource::class];
    }

    /**
     * One record inside the rep's reach and one outside, per resource.
     *
     * @return array<class-string<\Filament\Resources\Resource>, array{Model, Model}>
     */
    private function records(User $rep, User $other): array
    {
        return [
            LeadResource::class => [
                Lead::factory()->create(['owner_id' => $rep->getKey()]),
                Lead::factory()->create(['owner_id' => $other->getKey()]),
            ],
            ContactResource::class => [
                Contact::factory()->create(['owner_id' => $rep->getKey()]),
                Contact::factory()->create(['owner_id' => $other->getKey()]),
            ],
            AccountResource::class => [
                Account::factory()->create(['owner_id' => $rep->getKey()]),
                Account::factory()->create(['owner_id' => $other->getKey()]),
            ],
            DealResource::class => [
                Deal::factory()->create(['owner_id' => $rep->getKey()]),
                Deal::factory()->create(['owner_id' => $other->getKey()]),
            ],
            TaskResource::class => [
                Task::factory()->create(['assignee_id' => $rep->getKey()]),
                Task::factory()->create(['assignee_id' => $other->getKey()]),
            ],
        ];
    }

    #[Test]
    public function a_sales_rep_only_searches_records_inside_their_reach_while_an_admin_sees_everything(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $admin = $this->admin();

        foreach ($this->records($rep, $other) as $resource => [$mine, $theirs]) {
            $this->actingAs($rep);

            $this->assertTrue($resource::canGloballySearch(), $resource.' should be searchable by a rep');
            $this->assertSame(
                [$mine->getKey()],
                $resource::getGlobalSearchEloquentQuery()->pluck('id')->all(),
                $resource.' leaked a record outside the rep\'s reach',
            );

            $this->actingAs($admin);

            $this->assertTrue($resource::canGloballySearch(), $resource.' should be searchable by an admin');

            $all = $resource::getGlobalSearchEloquentQuery()->pluck('id')->all();

            $this->assertContains($mine->getKey(), $all, $resource.' hid the rep\'s record from the admin');
            $this->assertContains($theirs->getKey(), $all, $resource.' hid the other rep\'s record from the admin');
        }
    }

    #[Test]
    public function a_user_without_view_any_is_not_offered_the_resource_and_its_query_yields_nothing(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $this->records($rep, $other);

        $this->actingAs(User::factory()->create());

        foreach (self::resources() as $resource) {
            $this->assertFalse($resource::canGloballySearch(), $resource.' should not be searchable without view_any');
            $this->assertSame([], $resource::getGlobalSearchEloquentQuery()->pluck('id')->all(), $resource.' returned rows without view_any');
        }
    }

    #[Test]
    public function tasks_are_searchable_by_title_and_description_and_show_assignee_due_date_and_status(): void
    {
        $rep = $this->salesRep();
        $task = Task::factory()->create([
            'assignee_id' => $rep->getKey(),
            'title' => 'Call back',
            'description' => 'Discuss the Zeta renewal',
            'due_at' => now()->addDay()->setTime(9, 30),
        ]);

        $this->actingAs($rep);

        $this->assertContains('title', TaskResource::getGloballySearchableAttributes());
        $this->assertContains('description', TaskResource::getGloballySearchableAttributes());

        $details = TaskResource::getGlobalSearchResultDetails($task->fresh() ?? $task);

        $this->assertSame($rep->name, $details[__('tasks.fields.assignee')]);
        $this->assertSame($task->due_at?->format('Y-m-d H:i'), $details[__('tasks.fields.due_at')]);
        $this->assertSame(TaskStatus::Pending->getLabel(), $details[__('tasks.fields.status')]);
    }

    #[Test]
    public function a_task_linked_to_a_reps_own_lead_is_searchable_even_when_assigned_to_someone_else(): void
    {
        $rep = $this->salesRep();
        $other = $this->salesRep();
        $lead = Lead::factory()->create(['owner_id' => $rep->getKey()]);
        $linked = Task::factory()->create(['assignee_id' => $other->getKey(), 'lead_id' => $lead->getKey()]);
        $unlinked = Task::factory()->create(['assignee_id' => $other->getKey()]);

        $this->actingAs($rep);

        $ids = TaskResource::getGlobalSearchEloquentQuery()->pluck('id')->all();

        $this->assertContains($linked->getKey(), $ids);
        $this->assertNotContains($unlinked->getKey(), $ids);
    }
}
