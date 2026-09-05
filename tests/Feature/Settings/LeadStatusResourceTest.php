<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\ActivityLogEvent;
use App\Enums\BadgeColor;
use App\Enums\LeadStatusKind;
use App\Exceptions\Settings\InvalidLeadStatusException;
use App\Filament\Resources\LeadStatuses\LeadStatusResource;
use App\Filament\Resources\LeadStatuses\Pages\CreateLeadStatus;
use App\Filament\Resources\LeadStatuses\Pages\EditLeadStatus;
use App\Filament\Resources\LeadStatuses\Pages\ListLeadStatuses;
use App\Models\LeadStatus;
use App\Services\Settings\LeadStatusService;
use Database\Seeders\LeadStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class LeadStatusResourceTest extends TestCase
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
    public function admins_manage_lead_statuses_and_sales_managers_are_refused(): void
    {
        $admin = $this->admin();
        $manager = $this->salesManager();
        $status = $this->makeStatus();

        $this->actingAs($admin)->get(LeadStatusResource::getUrl('index'))->assertOk();
        $this->actingAs($admin)->get(LeadStatusResource::getUrl('create'))->assertOk();
        $this->actingAs($manager)->get(LeadStatusResource::getUrl('index'))->assertForbidden();
        $this->actingAs($manager)->get(LeadStatusResource::getUrl('create'))->assertForbidden();
        $this->actingAs($manager)->get(LeadStatusResource::getUrl('edit', ['record' => $status]))->assertForbidden();

        Livewire::actingAs($admin)->test(ListLeadStatuses::class)->assertCanSeeTableRecords([$status]);
    }

    #[Test]
    public function a_bulk_delete_skips_the_default_and_converted_statuses(): void
    {
        $admin = $this->admin();
        $default = $this->makeStatus('New', 'جديد', default: true);
        $converted = $this->makeStatus('Converted', 'محوّل', kind: LeadStatusKind::Converted);
        $ordinary = $this->makeStatus('Contacted', 'تم التواصل');

        Livewire::actingAs($admin)
            ->test(ListLeadStatuses::class)
            ->callTableBulkAction('delete', [$default, $converted, $ordinary]);

        $this->assertDatabaseHas('lead_statuses', ['id' => $default->getKey()]);
        $this->assertDatabaseHas('lead_statuses', ['id' => $converted->getKey()]);
        $this->assertDatabaseMissing('lead_statuses', ['id' => $ordinary->getKey()]);
        $this->assertSame(1, LeadStatus::query()->where('is_default', true)->count());
        $this->assertSame(1, LeadStatus::query()->where('kind', LeadStatusKind::Converted->value)->count());
    }

    #[Test]
    public function a_status_is_created_with_both_names_and_audited(): void
    {
        $admin = $this->admin();
        $this->makeStatus(default: true);

        Livewire::actingAs($admin)
            ->test(CreateLeadStatus::class)
            ->fillForm([
                'name_ar' => 'قيد التفاوض',
                'name_en' => 'Negotiating',
                'kind' => LeadStatusKind::Working->value,
                'color' => BadgeColor::Primary->value,
                'is_default' => false,
                'is_active' => true,
                'sort' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $status = LeadStatus::query()->where('name_en', 'Negotiating')->firstOrFail();

        $this->assertSame(LeadStatusKind::Working, $status->kind);
        $this->assertSame(BadgeColor::Primary, $status->color);
        $this->assertFalse($status->is_default);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupCreated->value,
            'subject_type' => LeadStatus::class,
            'subject_id' => $status->getKey(),
        ]);
    }

    #[Test]
    public function both_names_are_required_and_unique(): void
    {
        $admin = $this->admin();
        $this->makeStatus('Contacted', 'تم التواصل');

        Livewire::actingAs($admin)
            ->test(CreateLeadStatus::class)
            ->fillForm(['name_ar' => 'تم التواصل', 'name_en' => '', 'kind' => LeadStatusKind::Working->value])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'unique', 'name_en' => 'required']);

        Livewire::actingAs($admin)
            ->test(CreateLeadStatus::class)
            ->fillForm(['name_ar' => '', 'name_en' => 'Contacted', 'kind' => LeadStatusKind::Working->value])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'required', 'name_en' => 'unique']);
    }

    #[Test]
    public function the_display_name_follows_the_locale(): void
    {
        $status = $this->makeStatus('Contacted', 'تم التواصل');

        app()->setLocale('ar');
        $this->assertSame('تم التواصل', $status->display_name);

        app()->setLocale('en');
        $this->assertSame('Contacted', $status->display_name);

        app()->setLocale('ar');
    }

    #[Test]
    public function an_admin_edits_a_status(): void
    {
        $admin = $this->admin();
        $this->makeStatus(default: true);
        $status = $this->makeStatus('Contacted', 'تم التواصل');

        Livewire::actingAs($admin)
            ->test(EditLeadStatus::class, ['record' => $status->getRouteKey()])
            ->fillForm(['name_en' => 'Reached', 'color' => BadgeColor::Success->value, 'sort' => 7])
            ->call('save')
            ->assertHasNoFormErrors();

        $status->refresh();

        $this->assertSame('Reached', $status->name_en);
        $this->assertSame(BadgeColor::Success, $status->color);
        $this->assertSame(7, $status->sort);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupUpdated->value,
            'subject_type' => LeadStatus::class,
            'subject_id' => $status->getKey(),
        ]);
    }

    #[Test]
    public function saving_a_status_as_default_clears_the_flag_on_every_other_status(): void
    {
        $admin = $this->admin();
        $previous = $this->makeStatus('New', 'جديد', default: true);
        $next = $this->makeStatus('Contacted', 'تم التواصل');

        Livewire::actingAs($admin)
            ->test(EditLeadStatus::class, ['record' => $next->getRouteKey()])
            ->fillForm(['is_default' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($next->refresh()->is_default);
        $this->assertFalse($previous->refresh()->is_default);
        $this->assertSame(1, LeadStatus::query()->where('is_default', true)->count());

        Livewire::actingAs($admin)
            ->test(CreateLeadStatus::class)
            ->fillForm([
                'name_ar' => 'وارد',
                'name_en' => 'Incoming',
                'kind' => LeadStatusKind::New->value,
                'color' => BadgeColor::Info->value,
                'is_default' => true,
                'is_active' => true,
                'sort' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse($next->refresh()->is_default);
        $this->assertSame(1, LeadStatus::query()->where('is_default', true)->count());
        $this->assertTrue(LeadStatus::query()->where('name_en', 'Incoming')->firstOrFail()->is_default);
    }

    #[Test]
    public function the_edit_page_keeps_the_saved_record_instance_in_sync(): void
    {
        $this->makeStatus(default: true);
        $status = $this->makeStatus('Contacted', 'تم التواصل');

        $saved = app(LeadStatusService::class)->update($status, ['name_en' => 'Reached', 'sort' => 9]);

        $this->assertSame($status, $saved);
        $this->assertSame('Reached', $status->name_en);
        $this->assertSame(9, $status->sort);
        $this->assertFalse($status->isDirty());
        $this->assertSame('Reached', $status->fresh()?->name_en);
    }

    #[Test]
    public function the_first_status_ever_created_becomes_the_default(): void
    {
        $status = app(LeadStatusService::class)->create([
            'name_ar' => 'جديد',
            'name_en' => 'New',
            'kind' => LeadStatusKind::New,
            'color' => BadgeColor::Info,
            'is_default' => false,
            'is_active' => true,
            'sort' => 1,
        ]);

        $this->assertTrue($status->is_default);
    }

    #[Test]
    public function the_default_status_cannot_be_deactivated_or_unset(): void
    {
        $admin = $this->admin();
        $default = $this->makeStatus('New', 'جديد', default: true);

        Livewire::actingAs($admin)
            ->test(EditLeadStatus::class, ['record' => $default->getRouteKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertNotified(__('lead_statuses.validation.default_cannot_be_deactivated'));

        $this->assertTrue($default->refresh()->is_active);

        Livewire::actingAs($admin)
            ->test(EditLeadStatus::class, ['record' => $default->getRouteKey()])
            ->assertFormFieldDisabled('is_default');

        try {
            app(LeadStatusService::class)->update($default, ['is_default' => false]);
            $this->fail('The default flag was unset.');
        } catch (InvalidLeadStatusException $exception) {
            $this->assertSame(__('lead_statuses.validation.default_cannot_be_unset'), $exception->getMessage());
        }

        $this->assertTrue($default->refresh()->is_default);

        Livewire::actingAs($admin)
            ->test(CreateLeadStatus::class)
            ->fillForm([
                'name_ar' => 'وارد',
                'name_en' => 'Incoming',
                'kind' => LeadStatusKind::New->value,
                'color' => BadgeColor::Info->value,
                'is_default' => true,
                'is_active' => false,
                'sort' => 0,
            ])
            ->call('create')
            ->assertNotified(__('lead_statuses.validation.default_cannot_be_deactivated'));

        $this->assertDatabaseMissing('lead_statuses', ['name_en' => 'Incoming']);
    }

    #[Test]
    public function the_default_status_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $default = $this->makeStatus('New', 'جديد', default: true);
        $other = $this->makeStatus('Contacted', 'تم التواصل');

        $this->assertFalse($admin->can('delete', $default));
        $this->assertTrue($admin->can('delete', $other));

        try {
            app(LeadStatusService::class)->delete($default);
            $this->fail('The default status was deleted.');
        } catch (InvalidLeadStatusException $exception) {
            $this->assertSame(__('lead_statuses.validation.default_cannot_be_deleted'), $exception->getMessage());
        }

        $this->assertDatabaseHas('lead_statuses', ['id' => $default->getKey()]);

        Livewire::actingAs($admin)
            ->test(EditLeadStatus::class, ['record' => $default->getRouteKey()])
            ->assertActionHidden('delete');
    }

    #[Test]
    public function a_second_converted_status_is_refused(): void
    {
        $admin = $this->admin();
        $this->makeStatus('New', 'جديد', default: true);
        $converted = $this->makeStatus('Converted', 'محوّل', kind: LeadStatusKind::Converted);
        $working = $this->makeStatus('Contacted', 'تم التواصل');

        Livewire::actingAs($admin)
            ->test(CreateLeadStatus::class)
            ->fillForm([
                'name_ar' => 'محوّل ثانية',
                'name_en' => 'Converted again',
                'kind' => LeadStatusKind::Converted->value,
                'color' => BadgeColor::Warning->value,
                'is_default' => false,
                'is_active' => true,
                'sort' => 9,
            ])
            ->call('create')
            ->assertNotified(__('lead_statuses.validation.converted_exists'));

        $this->assertDatabaseMissing('lead_statuses', ['name_en' => 'Converted again']);

        Livewire::actingAs($admin)
            ->test(EditLeadStatus::class, ['record' => $working->getRouteKey()])
            ->fillForm(['kind' => LeadStatusKind::Converted->value])
            ->call('save')
            ->assertNotified(__('lead_statuses.validation.converted_exists'));

        $this->assertSame(LeadStatusKind::Working, $working->refresh()->kind);
        $this->assertSame(1, LeadStatus::query()->where('kind', LeadStatusKind::Converted->value)->count());

        Livewire::actingAs($admin)
            ->test(EditLeadStatus::class, ['record' => $converted->getRouteKey()])
            ->fillForm(['name_en' => 'Won as customer', 'name_ar' => 'أصبح عميلاً'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Won as customer', $converted->refresh()->name_en);
    }

    #[Test]
    public function the_converted_status_keeps_its_kind_and_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $this->makeStatus('New', 'جديد', default: true);
        $converted = $this->makeStatus('Converted', 'محوّل', kind: LeadStatusKind::Converted);

        Livewire::actingAs($admin)
            ->test(EditLeadStatus::class, ['record' => $converted->getRouteKey()])
            ->assertFormFieldDisabled('kind');

        try {
            app(LeadStatusService::class)->update($converted, ['kind' => LeadStatusKind::Working]);
            $this->fail('The Converted status changed kind.');
        } catch (InvalidLeadStatusException $exception) {
            $this->assertSame(__('lead_statuses.validation.converted_kind_locked'), $exception->getMessage());
        }

        $this->assertSame(LeadStatusKind::Converted, $converted->refresh()->kind);

        $this->assertFalse($admin->can('delete', $converted));

        try {
            app(LeadStatusService::class)->delete($converted);
            $this->fail('The Converted status was deleted.');
        } catch (InvalidLeadStatusException $exception) {
            $this->assertSame(__('lead_statuses.validation.converted_cannot_be_deleted'), $exception->getMessage());
        }

        $this->assertDatabaseHas('lead_statuses', ['id' => $converted->getKey()]);
    }

    #[Test]
    public function an_ordinary_status_is_deleted_through_the_service_and_audited(): void
    {
        $admin = $this->admin();
        $this->makeStatus('New', 'جديد', default: true);
        $status = $this->makeStatus('Contacted', 'تم التواصل');

        Livewire::actingAs($admin)
            ->test(EditLeadStatus::class, ['record' => $status->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('lead_statuses', ['id' => $status->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupDeleted->value,
            'subject_type' => LeadStatus::class,
            'subject_id' => $status->getKey(),
        ]);
    }

    #[Test]
    public function the_seeder_creates_the_defaults_idempotently(): void
    {
        $this->seed(LeadStatusSeeder::class);
        $this->seed(LeadStatusSeeder::class);

        $this->assertSame(5, LeadStatus::query()->count());
        $this->assertSame(1, LeadStatus::query()->where('is_default', true)->count());
        $this->assertSame(1, LeadStatus::query()->where('kind', LeadStatusKind::Converted->value)->count());

        $expected = [
            ['New', 'جديد', LeadStatusKind::New, BadgeColor::Info, true, 1],
            ['Contacted', 'تم التواصل', LeadStatusKind::Working, BadgeColor::Primary, false, 2],
            ['Qualified', 'مؤهل', LeadStatusKind::Qualified, BadgeColor::Success, false, 3],
            ['Converted', 'محوّل', LeadStatusKind::Converted, BadgeColor::Warning, false, 4],
            ['Unqualified', 'غير مؤهل', LeadStatusKind::Unqualified, BadgeColor::Gray, false, 5],
        ];

        foreach ($expected as [$nameEn, $nameAr, $kind, $color, $isDefault, $sort]) {
            $status = LeadStatus::query()->where('name_en', $nameEn)->firstOrFail();

            $this->assertSame($nameAr, $status->name_ar);
            $this->assertSame($kind, $status->kind);
            $this->assertSame($color, $status->color);
            $this->assertSame($isDefault, $status->is_default);
            $this->assertSame($sort, $status->sort);
            $this->assertTrue($status->is_active);
        }
    }

    #[Test]
    public function the_seeder_never_creates_a_second_default_or_converted_status(): void
    {
        $this->makeStatus('Incoming', 'وارد', default: true);
        $this->makeStatus('Customer', 'عميل', kind: LeadStatusKind::Converted);

        $this->seed(LeadStatusSeeder::class);

        $this->assertSame(6, LeadStatus::query()->count());
        $this->assertSame(1, LeadStatus::query()->where('is_default', true)->count());
        $this->assertSame(1, LeadStatus::query()->where('kind', LeadStatusKind::Converted->value)->count());
        $this->assertTrue(LeadStatus::query()->where('name_en', 'Incoming')->firstOrFail()->is_default);
        $this->assertFalse(LeadStatus::query()->where('name_en', 'New')->firstOrFail()->is_default);
        $this->assertDatabaseMissing('lead_statuses', ['name_en' => 'Converted']);
    }

    private function makeStatus(
        string $nameEn = 'Working',
        string $nameAr = 'قيد المتابعة',
        LeadStatusKind $kind = LeadStatusKind::Working,
        bool $default = false,
    ): LeadStatus {
        return LeadStatus::factory()->create([
            'name_en' => $nameEn,
            'name_ar' => $nameAr,
            'kind' => $default ? LeadStatusKind::New : $kind,
            'is_default' => $default,
        ]);
    }
}
