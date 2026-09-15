<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Localisation;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Services\Settings\SettingsRepository;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The organisation timezone setting promises "dates and times are displayed
 * in it across the system" (settings.general.helpers.timezone). Only the
 * calendar, the reports and the timeline tooltip read it; every Filament
 * dateTime() column and entry renders in config('app.timezone') because
 * FilamentTimezone is never set from the setting.
 */
final class OrganisationTimezoneDisplayTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function record_pages_display_date_times_in_the_organisation_timezone(): void
    {
        $this->seedAccess();
        $this->usePanel();

        $admin = $this->superAdmin();
        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'UTC'], $admin);

        $task = Task::factory()->create([
            'assignee_id' => $admin->getKey(),
            'created_by' => $admin->getKey(),
            'due_at' => Carbon::parse('2026-09-20 10:00:00', (string) config('app.timezone')),
        ]);

        $this->actingAs($admin)
            ->get(TaskResource::getUrl('view', ['record' => $task]))
            ->assertOk()
            ->assertSee('2026-09-20 07:00')
            ->assertDontSee('2026-09-20 10:00');

        $this->assertSame('UTC', FilamentTimezone::get(), 'Filament renders date-times in app.timezone, not the organisation setting');
    }
}
