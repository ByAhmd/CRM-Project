<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Filament\Pages\Calendar;
use App\Filament\Pages\DealBoard;
use App\Filament\Pages\TasksBoard;
use App\Filament\Resources\Contacts\ContactResource;
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
 * resting on a link prefetches it — in every panel (D-23). Two exceptions
 * load in full: the calendar, whose FullCalendar module is a page-level Vite
 * entry that must run before the page's Alpine component starts, and every
 * link into another panel, which boots its own navigation and top bar.
 */
final class SpaModeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function every_panel_navigates_in_place_and_prefetches_on_hover(): void
    {
        $this->assertCount(5, Filament::getPanels());

        foreach (Filament::getPanels() as $id => $panel) {
            $this->assertTrue($panel->hasSpaMode(), "{$id} has no SPA mode");
            $this->assertTrue($panel->hasSpaPrefetching(), "{$id} does not prefetch");
        }
    }

    #[Test]
    public function navigation_links_inside_a_workspace_swap_the_page(): void
    {
        $this->seedAccess();
        $this->seedLookups();

        $html = (string) $this->actingAs($this->admin())->get(LeadResource::getUrl('index'))->assertOk()->getContent();

        foreach ([LeadResource::getUrl('index'), ContactResource::getUrl('index'), DealBoard::getUrl()] as $url) {
            $this->assertMatchesRegularExpression(
                '/href="'.preg_quote(e($url), '/').'" wire:navigate\.hover/',
                $html,
                "the navigation link to {$url} does not navigate in place",
            );
        }
    }

    #[Test]
    public function the_calendar_loads_in_full_while_the_rest_of_the_tasks_navigation_swaps(): void
    {
        $this->seedAccess();
        $this->seedLookups();

        $html = (string) $this->actingAs($this->admin())->get(TaskResource::getUrl('index'))->assertOk()->getContent();

        foreach ([TaskResource::getUrl('index'), TasksBoard::getUrl()] as $url) {
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
