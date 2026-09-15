<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\ActivityLogEvent;
use App\Enums\LeadScoringRuleKind;
use App\Filament\Resources\LeadSources\LeadSourceResource;
use App\Filament\Resources\LeadSources\Pages\CreateLeadSource;
use App\Filament\Resources\LeadSources\Pages\EditLeadSource;
use App\Filament\Resources\LeadSources\Pages\ListLeadSources;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadScoringRule;
use App\Models\LeadSource;
use Database\Seeders\LeadSourceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class LeadSourceResourceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
    }

    #[Test]
    public function admins_list_lead_sources_and_sales_managers_are_refused(): void
    {
        $admin = $this->admin();
        $manager = $this->salesManager();
        $source = $this->makeLeadSource();

        $this->actingAs($admin)->get(LeadSourceResource::getUrl('index'))->assertOk();
        $this->actingAs($manager)->get(LeadSourceResource::getUrl('index'))->assertForbidden();
        $this->actingAs($manager)->get(LeadSourceResource::getUrl('create'))->assertForbidden();
        $this->actingAs($manager)->get(LeadSourceResource::getUrl('edit', ['record' => $source]))->assertForbidden();

        Livewire::actingAs($admin)->test(ListLeadSources::class)->assertCanSeeTableRecords([$source]);
    }

    #[Test]
    public function roles_without_settings_manage_are_refused_at_the_livewire_level(): void
    {
        $manager = $this->salesManager();
        $rep = $this->salesRep();
        $source = $this->makeLeadSource();

        Livewire::actingAs($manager)->test(ListLeadSources::class)->assertForbidden();
        Livewire::actingAs($rep)->test(ListLeadSources::class)->assertForbidden();
        Livewire::actingAs($rep)->test(CreateLeadSource::class)->assertForbidden();
        Livewire::actingAs($rep)->test(EditLeadSource::class, ['record' => $source->getRouteKey()])->assertForbidden();

        $this->assertDatabaseHas('lead_sources', ['id' => $source->getKey()]);
    }

    #[Test]
    public function a_lead_source_is_created_with_both_names_and_audited(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateLeadSource::class)
            ->fillForm([
                'name_ar' => 'معرض تجاري',
                'name_en' => 'Trade show',
                'is_active' => true,
                'sort' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $source = LeadSource::query()->where('name_en', 'Trade show')->firstOrFail();

        $this->assertSame('معرض تجاري', $source->name_ar);
        $this->assertTrue($source->is_active);
        $this->assertSame(3, $source->sort);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupCreated->value,
            'subject_type' => LeadSource::class,
            'subject_id' => $source->getKey(),
            'causer_id' => $admin->getKey(),
        ]);
    }

    #[Test]
    public function an_admin_edits_a_lead_source_and_the_change_is_audited(): void
    {
        $admin = $this->admin();
        $source = $this->makeLeadSource();

        Livewire::actingAs($admin)
            ->test(EditLeadSource::class, ['record' => $source->getRouteKey()])
            ->fillForm([
                'name_ar' => 'شريك',
                'name_en' => 'Partner',
                'is_active' => false,
                'sort' => 7,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $source->refresh();

        $this->assertSame('Partner', $source->name_en);
        $this->assertSame('شريك', $source->name_ar);
        $this->assertFalse($source->is_active);
        $this->assertSame(7, $source->sort);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupUpdated->value,
            'subject_type' => LeadSource::class,
            'subject_id' => $source->getKey(),
        ]);
    }

    #[Test]
    public function both_names_are_required(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateLeadSource::class)
            ->fillForm(['name_ar' => '', 'name_en' => ''])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'required', 'name_en' => 'required']);
    }

    #[Test]
    public function both_names_are_unique(): void
    {
        $this->makeLeadSource('Website', 'الموقع الإلكتروني');

        Livewire::actingAs($this->admin())
            ->test(CreateLeadSource::class)
            ->fillForm(['name_ar' => 'الموقع الإلكتروني', 'name_en' => 'Website'])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'unique', 'name_en' => 'unique']);
    }

    #[Test]
    public function a_lead_source_keeps_its_own_names_when_edited(): void
    {
        $source = $this->makeLeadSource('Website', 'الموقع الإلكتروني');

        Livewire::actingAs($this->admin())
            ->test(EditLeadSource::class, ['record' => $source->getRouteKey()])
            ->fillForm(['name_ar' => 'الموقع الإلكتروني', 'name_en' => 'Website', 'sort' => 1])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, $source->refresh()->sort);
    }

    #[Test]
    public function the_display_name_follows_the_locale(): void
    {
        $source = $this->makeLeadSource('Referral', 'إحالة');

        app()->setLocale('ar');
        $this->assertSame('إحالة', $source->display_name);
        $this->assertSame('name_ar', LeadSource::localisedNameColumn());

        app()->setLocale('en');
        $this->assertSame('Referral', $source->display_name);
        $this->assertSame('name_en', LeadSource::localisedNameColumn());

        app()->setLocale('ar');
    }

    #[Test]
    public function the_table_searches_both_names_and_sorts_by_sort_order(): void
    {
        $admin = $this->admin();
        $second = $this->makeLeadSource('Referral', 'إحالة', 20);
        $first = $this->makeLeadSource('Website', 'الموقع الإلكتروني', 10);

        Livewire::actingAs($admin)
            ->test(ListLeadSources::class)
            ->assertCanSeeTableRecords([$first, $second], inOrder: true)
            ->searchTable('إحالة')
            ->assertCanSeeTableRecords([$second])
            ->assertCanNotSeeTableRecords([$first])
            ->searchTable('Web')
            ->assertCanSeeTableRecords([$first])
            ->assertCanNotSeeTableRecords([$second]);
    }

    #[Test]
    public function the_table_filters_by_active_state(): void
    {
        $admin = $this->admin();
        $active = $this->makeLeadSource('Website', 'الموقع الإلكتروني');
        $inactive = LeadSource::factory()->inactive()->create(['name_en' => 'Fax', 'name_ar' => 'فاكس']);

        Livewire::actingAs($admin)
            ->test(ListLeadSources::class)
            ->filterTable('is_active', true)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive])
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$inactive])
            ->assertCanNotSeeTableRecords([$active]);
    }

    #[Test]
    public function admins_reorder_lead_sources_by_dragging(): void
    {
        $admin = $this->admin();
        $website = $this->makeLeadSource('Website', 'الموقع الإلكتروني', 1);
        $referral = $this->makeLeadSource('Referral', 'إحالة', 2);
        $event = $this->makeLeadSource('Event', 'فعالية', 3);

        Livewire::actingAs($admin)
            ->test(ListLeadSources::class)
            ->call('reorderTable', [$event->getKey(), $website->getKey(), $referral->getKey()]);

        $this->assertSame(1, $event->refresh()->sort);
        $this->assertSame(2, $website->refresh()->sort);
        $this->assertSame(3, $referral->refresh()->sort);
    }

    #[Test]
    public function a_lead_source_is_deleted_permanently_and_audited(): void
    {
        $admin = $this->admin();
        $source = $this->makeLeadSource();

        Livewire::actingAs($admin)
            ->test(EditLeadSource::class, ['record' => $source->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('lead_sources', ['id' => $source->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupDeleted->value,
            'subject_type' => LeadSource::class,
            'subject_id' => $source->getKey(),
        ]);
    }

    #[Test]
    public function admins_bulk_delete_lead_sources(): void
    {
        $admin = $this->admin();
        $first = $this->makeLeadSource('Website', 'الموقع الإلكتروني');
        $second = $this->makeLeadSource('Referral', 'إحالة');

        Livewire::actingAs($admin)
            ->test(ListLeadSources::class)
            ->callTableBulkAction('delete', [$first, $second]);

        $this->assertDatabaseMissing('lead_sources', ['id' => $first->getKey()]);
        $this->assertDatabaseMissing('lead_sources', ['id' => $second->getKey()]);
    }

    #[Test]
    public function the_seeder_creates_the_defaults_idempotently(): void
    {
        $this->seed(LeadSourceSeeder::class);

        $this->assertSame(count(LeadSourceSeeder::defaults()), LeadSource::query()->count());
        $this->assertDatabaseHas('lead_sources', ['name_en' => 'Website', 'name_ar' => 'الموقع الإلكتروني', 'is_active' => true]);
        $this->assertDatabaseHas('lead_sources', ['name_en' => 'Other', 'name_ar' => 'أخرى']);

        $this->assertSame(
            array_keys(LeadSourceSeeder::defaults()),
            LeadSource::query()->orderBy('sort')->pluck('name_en')->all(),
            'defaults are ordered as listed',
        );

        LeadSource::query()->where('name_en', 'Referral')->update(['name_ar' => 'توصية', 'is_active' => false]);

        $this->seed(LeadSourceSeeder::class);

        $this->assertSame(count(LeadSourceSeeder::defaults()), LeadSource::query()->count());
        $this->assertDatabaseHas('lead_sources', ['name_en' => 'Referral', 'name_ar' => 'توصية', 'is_active' => false]);

        LeadSource::query()->where('name_en', 'Website')->update(['name_en' => 'Web site']);

        $this->seed(LeadSourceSeeder::class);

        $this->assertSame(count(LeadSourceSeeder::defaults()), LeadSource::query()->count());
        $this->assertDatabaseHas('lead_sources', ['name_en' => 'Web site', 'name_ar' => 'الموقع الإلكتروني']);
        $this->assertDatabaseMissing('lead_sources', ['name_en' => 'Website']);

        LeadSource::query()->where('name_en', 'Other')->delete();

        $this->seed(LeadSourceSeeder::class);

        $this->assertSame(count(LeadSourceSeeder::defaults()), LeadSource::query()->count());
        $this->assertDatabaseHas('lead_sources', ['name_en' => 'Other', 'name_ar' => 'أخرى', 'sort' => 90]);
    }

    #[Test]
    public function a_source_used_by_a_lead_a_deal_or_a_scoring_rule_is_never_deleted(): void
    {
        Queue::fake();
        $this->seedLookups();
        $admin = $this->admin();
        $byLead = $this->makeLeadSource('Exhibition', 'معرض');
        $byDeal = $this->makeLeadSource('Partner', 'شريك');
        $byRule = $this->makeLeadSource('Webinar', 'ندوة عبر الإنترنت');
        $unused = $this->makeLeadSource('Radio', 'إذاعة');

        $lead = Lead::factory()->create(['lead_source_id' => $byLead->getKey(), 'owner_id' => $admin->getKey()]);
        $lead->delete();
        $deal = Deal::factory()->create(['lead_source_id' => $byDeal->getKey(), 'owner_id' => $admin->getKey()]);
        $deal->delete();
        LeadScoringRule::factory()->create(['kind' => LeadScoringRuleKind::Source, 'reference_id' => $byRule->getKey(), 'field' => null]);

        foreach ([$byLead, $byDeal, $byRule] as $used) {
            $this->assertFalse($admin->can('delete', $used), "{$used->name_en} is in use and must not be deletable.");

            Livewire::actingAs($admin)
                ->test(EditLeadSource::class, ['record' => $used->getRouteKey()])
                ->assertActionHidden('delete');
        }

        $this->assertTrue($admin->can('delete', $unused));

        Livewire::actingAs($admin)
            ->test(ListLeadSources::class)
            ->callTableBulkAction('delete', [$byLead, $byDeal, $byRule, $unused]);

        $this->assertDatabaseHas('lead_sources', ['id' => $byLead->getKey()]);
        $this->assertDatabaseHas('lead_sources', ['id' => $byDeal->getKey()]);
        $this->assertDatabaseHas('lead_sources', ['id' => $byRule->getKey()]);
        $this->assertDatabaseMissing('lead_sources', ['id' => $unused->getKey()]);

        $this->expectException(QueryException::class);
        $byRule->delete();
    }

    private function makeLeadSource(string $nameEn = 'Website', string $nameAr = 'الموقع الإلكتروني', int $sort = 0): LeadSource
    {
        return LeadSource::factory()->create([
            'name_en' => $nameEn,
            'name_ar' => $nameAr,
            'sort' => $sort,
        ]);
    }
}
