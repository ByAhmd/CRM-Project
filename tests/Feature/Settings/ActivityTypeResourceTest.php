<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\ActivityKind;
use App\Enums\ActivityLogEvent;
use App\Enums\BadgeColor;
use App\Filament\Resources\ActivityTypes\ActivityTypeResource;
use App\Filament\Resources\ActivityTypes\Pages\CreateActivityType;
use App\Filament\Resources\ActivityTypes\Pages\EditActivityType;
use App\Filament\Resources\ActivityTypes\Pages\ListActivityTypes;
use App\Filament\Resources\ActivityTypes\Schemas\ActivityTypeForm;
use App\Models\Activity;
use App\Models\ActivityType;
use Database\Seeders\ActivityTypeSeeder;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class ActivityTypeResourceTest extends TestCase
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
    public function admins_manage_activity_types_and_sales_managers_are_refused(): void
    {
        $admin = $this->admin();
        $manager = $this->salesManager();
        $type = $this->makeActivityType();

        $this->actingAs($admin)->get(ActivityTypeResource::getUrl('index'))->assertOk();
        $this->actingAs($admin)->get(ActivityTypeResource::getUrl('create'))->assertOk();
        $this->actingAs($manager)->get(ActivityTypeResource::getUrl('index'))->assertForbidden();
        $this->actingAs($manager)->get(ActivityTypeResource::getUrl('create'))->assertForbidden();

        Livewire::actingAs($admin)->test(ListActivityTypes::class)->assertCanSeeTableRecords([$type]);
    }

    #[Test]
    public function an_activity_type_is_created_with_both_names_and_audited(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateActivityType::class)
            ->fillForm([
                'name_ar' => 'زيارة ميدانية',
                'name_en' => 'Site Visit',
                'kind' => ActivityKind::Meeting->value,
                'icon' => Heroicon::OutlinedBuildingOffice->name,
                'color' => BadgeColor::Success->value,
                'is_active' => true,
                'sort' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $type = ActivityType::query()->where('name_en', 'Site Visit')->firstOrFail();

        $this->assertSame(ActivityKind::Meeting, $type->kind);
        $this->assertSame(BadgeColor::Success, $type->color);
        $this->assertSame(Heroicon::OutlinedBuildingOffice, $type->heroicon());
        $this->assertFalse($type->is_system);
        $this->assertTrue($type->is_active);
        $this->assertSame(3, $type->sort);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupCreated->value,
            'subject_type' => ActivityType::class,
            'subject_id' => $type->getKey(),
        ]);
    }

    #[Test]
    public function both_names_are_required_and_unique(): void
    {
        $admin = $this->admin();
        $this->makeActivityType('Site Visit', 'زيارة ميدانية');

        Livewire::actingAs($admin)
            ->test(CreateActivityType::class)
            ->fillForm(['name_ar' => 'زيارة ميدانية', 'name_en' => '', 'kind' => ActivityKind::Other->value, 'color' => BadgeColor::Gray->value])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'unique', 'name_en' => 'required']);

        Livewire::actingAs($admin)
            ->test(CreateActivityType::class)
            ->fillForm(['name_ar' => '', 'name_en' => 'Site Visit', 'kind' => ActivityKind::Other->value, 'color' => BadgeColor::Gray->value])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'required', 'name_en' => 'unique']);
    }

    #[Test]
    public function the_kind_and_colour_are_required_and_the_icon_must_come_from_the_curated_list(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateActivityType::class)
            ->fillForm(['name_ar' => 'زيارة ميدانية', 'name_en' => 'Site Visit', 'kind' => null, 'color' => null, 'icon' => Heroicon::OutlinedTrash->name])
            ->call('create')
            ->assertHasFormErrors(['kind' => 'required', 'color' => 'required', 'icon']);
    }

    #[Test]
    public function the_icon_defaults_to_the_icon_of_the_chosen_kind(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateActivityType::class)
            ->fillForm(['kind' => ActivityKind::Call->value])
            ->assertFormSet(['icon' => Heroicon::OutlinedPhone->name])
            ->fillForm(['kind' => ActivityKind::Email->value])
            ->assertFormSet(['icon' => Heroicon::OutlinedEnvelope->name]);
    }

    #[Test]
    public function an_icon_chosen_deliberately_survives_a_change_of_kind(): void
    {
        $admin = $this->admin();
        $type = $this->makeActivityType('Site Visit', 'زيارة ميدانية', ['icon' => Heroicon::OutlinedBell->name]);

        Livewire::actingAs($admin)
            ->test(CreateActivityType::class)
            ->fillForm(['kind' => ActivityKind::Call->value, 'icon' => Heroicon::OutlinedBell->name])
            ->fillForm(['kind' => ActivityKind::Email->value])
            ->assertFormSet(['icon' => Heroicon::OutlinedBell->name]);

        Livewire::actingAs($admin)
            ->test(EditActivityType::class, ['record' => $type->getRouteKey()])
            ->fillForm(['kind' => ActivityKind::Meeting->value])
            ->assertFormSet(['icon' => Heroicon::OutlinedBell->name])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(Heroicon::OutlinedBell, $type->refresh()->heroicon());
        $this->assertSame(ActivityKind::Meeting, $type->kind);
    }

    #[Test]
    public function the_curated_icon_list_covers_every_kind_icon_with_a_translated_label(): void
    {
        $options = ActivityTypeForm::iconOptions();

        foreach (ActivityKind::cases() as $kind) {
            $this->assertArrayHasKey($kind->getIcon()->name, $options);
        }

        foreach (ActivityTypeForm::ICONS as $icon) {
            $this->assertSame(__('activity_types.options.icons.'.$icon->name, [], 'ar'), $options[$icon->name]);
            $this->assertNotSame('activity_types.options.icons.'.$icon->name, __('activity_types.options.icons.'.$icon->name, [], 'en'));
        }
    }

    #[Test]
    public function the_display_name_follows_the_locale(): void
    {
        $type = $this->makeActivityType('Site Visit', 'زيارة ميدانية');

        app()->setLocale('ar');
        $this->assertSame('زيارة ميدانية', $type->display_name);

        app()->setLocale('en');
        $this->assertSame('Site Visit', $type->display_name);

        app()->setLocale('ar');
    }

    #[Test]
    public function an_activity_type_is_edited_and_a_custom_row_may_change_its_kind(): void
    {
        $admin = $this->admin();
        $type = $this->makeActivityType();

        Livewire::actingAs($admin)
            ->test(EditActivityType::class, ['record' => $type->getRouteKey()])
            ->assertFormFieldEnabled('kind')
            ->fillForm([
                'name_en' => 'Demo Call',
                'kind' => ActivityKind::Call->value,
                'color' => BadgeColor::Info->value,
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $type->refresh();

        $this->assertSame('Demo Call', $type->name_en);
        $this->assertSame(ActivityKind::Call, $type->kind);
        $this->assertSame(Heroicon::OutlinedPhone, $type->heroicon());
        $this->assertSame(BadgeColor::Info, $type->color);
        $this->assertFalse($type->is_active);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupUpdated->value,
            'subject_type' => ActivityType::class,
            'subject_id' => $type->getKey(),
        ]);
    }

    #[Test]
    public function the_kind_of_a_system_row_is_locked_in_the_form_and_refused_by_the_observer(): void
    {
        $admin = $this->admin();
        $system = ActivityType::factory()->system(ActivityKind::Call)->create(['name_en' => 'Call', 'name_ar' => 'مكالمة']);

        Livewire::actingAs($admin)
            ->test(EditActivityType::class, ['record' => $system->getRouteKey()])
            ->assertFormFieldDisabled('kind')
            ->fillForm(['name_en' => 'Phone Call', 'kind' => ActivityKind::Email->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $system->refresh();

        $this->assertSame('Phone Call', $system->name_en);
        $this->assertSame(ActivityKind::Call, $system->kind);

        $this->expectException(LogicException::class);

        $system->update(['kind' => ActivityKind::Email]);
    }

    #[Test]
    public function a_system_row_cannot_be_deleted_but_a_custom_row_can(): void
    {
        $admin = $this->admin();
        $system = ActivityType::factory()->system(ActivityKind::Note)->create(['name_en' => 'Note', 'name_ar' => 'ملاحظة']);
        $custom = $this->makeActivityType();

        $this->assertFalse($admin->can('delete', $system));
        $this->assertTrue($admin->can('delete', $custom));
        $this->assertTrue($admin->can('deleteAny', ActivityType::class));

        Livewire::actingAs($admin)
            ->test(EditActivityType::class, ['record' => $system->getRouteKey()])
            ->assertActionHidden('delete');

        Livewire::actingAs($admin)
            ->test(ListActivityTypes::class)
            ->callTableBulkAction('delete', [$system, $custom]);

        $this->assertDatabaseHas('activity_types', ['id' => $system->getKey()]);
        $this->assertDatabaseMissing('activity_types', ['id' => $custom->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupDeleted->value,
            'subject_type' => ActivityType::class,
            'subject_id' => $custom->getKey(),
        ]);

        $this->expectException(LogicException::class);

        $system->delete();
    }

    #[Test]
    public function the_table_is_reorderable_by_sort(): void
    {
        $admin = $this->admin();
        $first = $this->makeActivityType('First', 'الأول', ['sort' => 0]);
        $second = $this->makeActivityType('Second', 'الثاني', ['sort' => 1]);
        $third = $this->makeActivityType('Third', 'الثالث', ['sort' => 2]);

        Livewire::actingAs($admin)
            ->test(ListActivityTypes::class)
            ->assertCanSeeTableRecords([$first, $second, $third], inOrder: true)
            ->call('reorderTable', [$third->getKey(), $first->getKey(), $second->getKey()]);

        $this->assertLessThan($first->refresh()->sort, $third->refresh()->sort);
        $this->assertLessThan($second->refresh()->sort, $first->sort);
        $this->assertSame(
            [$third->getKey(), $first->getKey(), $second->getKey()],
            ActivityType::query()->orderBy('sort')->pluck('id')->all(),
        );
    }

    #[Test]
    public function a_custom_type_used_by_a_logged_activity_is_never_deleted(): void
    {
        $this->seedLookups();
        $admin = $this->admin();
        $used = $this->makeActivityType('Site Visit', 'زيارة ميدانية', ['kind' => ActivityKind::Meeting, 'is_system' => false]);
        $unused = $this->makeActivityType('Workshop', 'ورشة عمل', ['kind' => ActivityKind::Meeting, 'is_system' => false]);
        Activity::factory()->create(['activity_type_id' => $used->getKey(), 'kind' => ActivityKind::Meeting, 'owner_id' => $admin->getKey()]);

        $this->assertFalse($admin->can('delete', $used));
        $this->assertTrue($admin->can('delete', $unused));

        Livewire::actingAs($admin)
            ->test(EditActivityType::class, ['record' => $used->getRouteKey()])
            ->assertActionHidden('delete');

        Livewire::actingAs($admin)
            ->test(ListActivityTypes::class)
            ->callTableBulkAction('delete', [$used, $unused]);

        $this->assertDatabaseHas('activity_types', ['id' => $used->getKey()]);
        $this->assertDatabaseMissing('activity_types', ['id' => $unused->getKey()]);
    }

    #[Test]
    public function the_database_refuses_an_unknown_colour(): void
    {
        $this->expectException(QueryException::class);

        ActivityType::query()->getConnection()->table('activity_types')->insert([
            'name_ar' => 'لون غير معروف',
            'name_en' => 'Unknown colour',
            'kind' => ActivityKind::Other->value,
            'color' => 'purple',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function the_database_refuses_an_unknown_kind(): void
    {
        $this->expectException(QueryException::class);

        ActivityType::query()->getConnection()->table('activity_types')->insert([
            'name_ar' => 'غير معروف',
            'name_en' => 'Unknown',
            'kind' => 'unknown',
            'color' => BadgeColor::Gray->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function the_seeder_creates_one_system_row_per_kind_idempotently(): void
    {
        $this->seed(ActivityTypeSeeder::class);
        $this->seed(ActivityTypeSeeder::class);

        $this->assertSame(count(ActivityKind::cases()), ActivityType::query()->count());

        foreach (ActivityKind::cases() as $index => $kind) {
            $row = ActivityType::query()->where('kind', $kind->value)->where('is_system', true)->firstOrFail();

            $this->assertSame(__('enums.activity_kind.'.$kind->value, [], 'ar'), $row->name_ar);
            $this->assertSame(__('enums.activity_kind.'.$kind->value, [], 'en'), $row->name_en);
            $this->assertSame($kind->getIcon(), $row->heroicon());
            $this->assertSame($kind->getColor(), $row->color->value);
            $this->assertSame($index, $row->sort);
            $this->assertTrue($row->is_active);
        }

        $call = ActivityType::query()->where('kind', ActivityKind::Call->value)->firstOrFail();
        $this->assertSame('مكالمة', $call->name_ar);
        $this->assertSame('Call', $call->name_en);

        $call->update(['name_en' => 'Phone Call']);

        $this->seed(ActivityTypeSeeder::class);

        $this->assertSame(count(ActivityKind::cases()), ActivityType::query()->count());
        $this->assertSame('Phone Call', $call->refresh()->name_en);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeActivityType(string $nameEn = 'Site Visit', string $nameAr = 'زيارة ميدانية', array $attributes = []): ActivityType
    {
        return ActivityType::factory()->create($attributes + [
            'name_en' => $nameEn,
            'name_ar' => $nameAr,
        ]);
    }
}
