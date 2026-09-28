<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Filament\Pages\Calendar;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Tasks\TaskResource;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * SPA mode (owner, 2026-09-28): the host is far from its users (A-24), so a
 * navigation click swaps the page content instead of reloading the panel, and
 * resting on a link prefetches it. The calendar is the one exception: its
 * FullCalendar module is a page-level @vite entry that must run before the
 * page's Alpine component starts, which only a full load guarantees.
 */
final class SpaModeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function the_panel_navigates_in_place_and_prefetches_on_hover(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->hasSpaMode());
        $this->assertTrue($panel->hasSpaPrefetching());
    }

    #[Test]
    public function navigation_links_swap_the_page_except_the_calendar_which_loads_in_full(): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $html = (string) $this->actingAs($this->admin())->get(LeadResource::getUrl('index'))->getContent();

        foreach ([LeadResource::getUrl('index'), TaskResource::getUrl('index')] as $url) {
            $this->assertMatchesRegularExpression(
                '/href="'.preg_quote(e($url), '/').'" wire:navigate\.hover/',
                $html,
                "the navigation link to {$url} does not navigate in place",
            );
        }

        $calendar = preg_quote(e(Calendar::getUrl()), '/');
        $this->assertMatchesRegularExpression('/href="'.$calendar.'"/', $html, 'the calendar link is missing from the navigation');
        $this->assertDoesNotMatchRegularExpression(
            '/href="'.$calendar.'" wire:navigate/',
            $html,
            'the calendar must load in full: its FullCalendar module has to run before its Alpine component',
        );
    }
}
