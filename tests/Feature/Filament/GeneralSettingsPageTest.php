<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ActivityLogEvent;
use App\Filament\Pages\Settings\GeneralSettings;
use App\Models\ActivityLog;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class GeneralSettingsPageTest extends TestCase
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
    public function defaults_come_from_config_and_the_page_is_gated(): void
    {
        $settings = app(SettingsRepository::class);

        $this->assertSame('SAR', $settings->currency());
        $this->assertSame('Asia/Riyadh', $settings->timezone());
        $this->assertSame(0, $settings->weekStartsOn());

        $this->actingAs($this->admin())->get(GeneralSettings::getUrl())->assertOk();
        $this->actingAs($this->salesManager())->get(GeneralSettings::getUrl())->assertForbidden();
    }

    #[Test]
    public function saving_updates_the_settings_and_writes_before_and_after_to_the_ledger(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(GeneralSettings::class)
            ->assertSchemaStateSet(['currency' => 'SAR'])
            ->fillForm([
                'organisation_name' => 'شركة الأفق',
                'currency' => 'AED',
                'timezone' => 'Asia/Dubai',
                'week_starts_on' => 1,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $settings = app(SettingsRepository::class);

        $this->assertSame('AED', $settings->currency());
        $this->assertSame('Asia/Dubai', $settings->timezone());
        $this->assertSame(1, $settings->weekStartsOn());
        $this->assertSame('شركة الأفق', $settings->organisationName());

        $log = ActivityLog::query()->where('description', ActivityLogEvent::SettingsUpdated->value)->latest('id')->firstOrFail();
        $changes = $log->properties->get('changes');

        $this->assertSame($admin->getKey(), (int) $log->causer_id);
        $this->assertSame('SAR', $changes[SettingsRepository::CURRENCY]['old']);
        $this->assertSame('AED', $changes[SettingsRepository::CURRENCY]['new']);
        $this->assertSame(0, $changes[SettingsRepository::WEEK_STARTS_ON]['old']);
        $this->assertSame(1, $changes[SettingsRepository::WEEK_STARTS_ON]['new']);
    }

    #[Test]
    public function an_invalid_currency_is_rejected(): void
    {
        Livewire::actingAs($this->admin())
            ->test(GeneralSettings::class)
            ->fillForm(['currency' => 'XXX'])
            ->call('save')
            ->assertHasFormErrors(['currency']);
    }
}
