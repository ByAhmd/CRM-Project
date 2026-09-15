<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Performance;

use App\Filament\Pages\DealBoard;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Probe: the organisation settings are read from the cache store once per
 * request, not once per rendered money cell. Production runs the file cache
 * (.env.example CACHE_STORE=file, D-1), where every SettingsRepository::all()
 * call is a file read plus an unserialize.
 */
final class SettingsMemoisationTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $this->admin = $this->admin();
    }

    #[Test]
    public function the_deal_list_reads_the_settings_store_a_constant_number_of_times(): void
    {
        Deal::factory()->count(5)->create(['owner_id' => $this->admin->getKey()]);
        $small = $this->settingsReadsDuring(fn () => $this->renderDeals());

        Deal::factory()->count(25)->create(['owner_id' => $this->admin->getKey()]);
        $large = $this->settingsReadsDuring(fn () => $this->renderDeals());

        $this->assertLessThanOrEqual($small + 2, $large, sprintf('crm.settings read %d times for 5 deals and %d times for 30 deals.', $small, $large));
    }

    #[Test]
    public function the_deal_board_reads_the_settings_store_a_constant_number_of_times(): void
    {
        Deal::factory()->count(5)->create(['owner_id' => $this->admin->getKey()]);
        $small = $this->settingsReadsDuring(fn () => Livewire::actingAs($this->admin)->test(DealBoard::class)->assertOk());

        Deal::factory()->count(20)->create(['owner_id' => $this->admin->getKey()]);
        $large = $this->settingsReadsDuring(fn () => Livewire::actingAs($this->admin)->test(DealBoard::class)->assertOk());

        $this->assertLessThanOrEqual($small + 2, $large, sprintf('crm.settings read %d times for 5 cards and %d times for 25 cards.', $small, $large));
    }

    private function renderDeals(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListDeals::class)
            ->set('activeTab', 'all')
            ->set('tableRecordsPerPage', 50)
            ->assertOk();
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function settingsReadsDuring(callable $callback): int
    {
        $reads = 0;
        $listener = static function (CacheHit|CacheMissed $event) use (&$reads): void {
            if (str_ends_with($event->key, 'crm.settings')) {
                $reads++;
            }
        };

        Event::listen(CacheHit::class, $listener);
        Event::listen(CacheMissed::class, $listener);

        try {
            $callback();
        } finally {
            Event::forget(CacheHit::class);
            Event::forget(CacheMissed::class);
        }

        return $reads;
    }
}
