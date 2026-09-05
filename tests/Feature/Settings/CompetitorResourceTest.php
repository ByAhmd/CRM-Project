<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\ActivityLogEvent;
use App\Filament\Resources\Competitors\CompetitorResource;
use App\Filament\Resources\Competitors\Pages\CreateCompetitor;
use App\Filament\Resources\Competitors\Pages\EditCompetitor;
use App\Filament\Resources\Competitors\Pages\ListCompetitors;
use App\Models\Competitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class CompetitorResourceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
    }

    private function makeCompetitor(string $name = 'Acme Corp', ?string $website = 'https://acme.example.com'): Competitor
    {
        return Competitor::factory()->create([
            'name' => $name,
            'website' => $website,
        ]);
    }

    #[Test]
    public function admins_manage_competitors_and_sales_managers_are_refused(): void
    {
        $admin = $this->admin();
        $manager = $this->salesManager();
        $competitor = $this->makeCompetitor();

        $this->actingAs($admin)->get(CompetitorResource::getUrl('index'))->assertOk();
        $this->actingAs($admin)->get(CompetitorResource::getUrl('create'))->assertOk();

        $this->actingAs($manager)->get(CompetitorResource::getUrl('index'))->assertForbidden();
        $this->actingAs($manager)->get(CompetitorResource::getUrl('create'))->assertForbidden();

        Livewire::actingAs($admin)->test(ListCompetitors::class)->assertCanSeeTableRecords([$competitor]);
    }

    #[Test]
    public function a_competitor_is_created_and_audited(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateCompetitor::class)
            ->fillForm([
                'name' => 'Globex',
                'website' => 'https://globex.example.com',
                'notes' => 'Strong on price, weak on support.',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $competitor = Competitor::query()->where('name', 'Globex')->firstOrFail();

        $this->assertSame('https://globex.example.com', $competitor->website);
        $this->assertSame('Strong on price, weak on support.', $competitor->notes);
        $this->assertTrue($competitor->is_active);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupCreated->value,
            'subject_type' => Competitor::class,
            'subject_id' => $competitor->getKey(),
        ]);
    }

    #[Test]
    public function a_competitor_is_edited(): void
    {
        $admin = $this->admin();
        $competitor = $this->makeCompetitor();

        Livewire::actingAs($admin)
            ->test(EditCompetitor::class, ['record' => $competitor->getRouteKey()])
            ->fillForm([
                'name' => 'Acme Holdings',
                'website' => null,
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $competitor->refresh();

        $this->assertSame('Acme Holdings', $competitor->name);
        $this->assertNull($competitor->website);
        $this->assertFalse($competitor->is_active);
    }

    #[Test]
    public function the_name_is_required_and_unique(): void
    {
        $admin = $this->admin();
        $this->makeCompetitor('Acme Corp');

        Livewire::actingAs($admin)
            ->test(CreateCompetitor::class)
            ->fillForm(['name' => ''])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);

        Livewire::actingAs($admin)
            ->test(CreateCompetitor::class)
            ->fillForm(['name' => 'Acme Corp'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);
    }

    #[Test]
    public function a_soft_deleted_namesake_is_reported_for_restoring_rather_than_recreated(): void
    {
        $admin = $this->admin();
        $deleted = $this->makeCompetitor('Acme Corp');
        $deleted->delete();

        // The live-record unique rule ignores trashed rows, so the dedicated
        // guard must reject the name with its own message instead of the
        // database index throwing.
        Livewire::actingAs($admin)
            ->test(CreateCompetitor::class)
            ->fillForm(['name' => 'Acme Corp'])
            ->call('create')
            ->assertHasFormErrors(['name'])
            ->assertHasNoFormErrors(['name' => 'unique']);

        $this->assertSame(1, Competitor::withTrashed()->where('name', 'Acme Corp')->count());

        $deleted->restore();

        Livewire::actingAs($admin)
            ->test(EditCompetitor::class, ['record' => $deleted->getRouteKey()])
            ->fillForm(['name' => 'Acme Corp'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    #[Test]
    public function the_website_must_be_a_valid_url_when_given(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateCompetitor::class)
            ->fillForm(['name' => 'Initech', 'website' => 'not a url'])
            ->call('create')
            ->assertHasFormErrors(['website' => 'url']);

        Livewire::actingAs($admin)
            ->test(CreateCompetitor::class)
            ->fillForm(['name' => 'Initech', 'website' => null])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('competitors', ['name' => 'Initech', 'website' => null]);
    }

    #[Test]
    public function a_competitor_is_soft_deleted_and_restorable(): void
    {
        $admin = $this->admin();
        $competitor = $this->makeCompetitor();

        Livewire::actingAs($admin)
            ->test(EditCompetitor::class, ['record' => $competitor->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted('competitors', ['id' => $competitor->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupDeleted->value,
            'subject_type' => Competitor::class,
            'subject_id' => $competitor->getKey(),
        ]);

        Livewire::actingAs($admin)
            ->test(EditCompetitor::class, ['record' => $competitor->getRouteKey()])
            ->callAction('restore');

        $this->assertNull($competitor->refresh()->deleted_at);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupRestored->value,
            'subject_type' => Competitor::class,
            'subject_id' => $competitor->getKey(),
        ]);
    }

    #[Test]
    public function the_trashed_filter_reveals_deleted_competitors(): void
    {
        $admin = $this->admin();
        $active = $this->makeCompetitor('Acme Corp');
        $deleted = $this->makeCompetitor('Globex');
        $deleted->delete();

        Livewire::actingAs($admin)
            ->test(ListCompetitors::class)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$deleted])
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$deleted])
            ->assertCanNotSeeTableRecords([$active])
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$active, $deleted]);
    }
}
