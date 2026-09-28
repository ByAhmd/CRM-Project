<?php

declare(strict_types=1);

namespace Tests\Feature\Views;

use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Lead;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * A row's three-dot menu must never slide under the sticky table header or
 * be clipped by the bounded table container (the owner's screenshot,
 * 2026-09-28). The menu panel lives inside its row; while the pointer is on
 * it the row is hovered, and the motion layer's lift (A-9) gives the row its
 * own stacking context below the header. Two halves, both pinned here:
 *
 * 1. clipping — every row menu uses Filament's fixed positioning strategy
 *    ("teleport"), so the scroll container cannot cut it off;
 * 2. stacking — theme.css lifts a row whose menu is open above the header.
 *    Filament's "teleport" does NOT move the panel out of its row (checked in
 *    a real browser), so without this rule the header still covers it.
 */
final class RowMenuTeleportTest extends TestCase
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

    #[Test]
    public function every_row_menu_on_the_lists_escapes_the_table_containers_clipping(): void
    {
        $admin = $this->admin();
        Lead::factory()->create(['owner_id' => $admin->getKey()]);
        Task::factory()->create(['assignee_id' => $admin->getKey()]);

        foreach ([ListLeads::class, ListTasks::class] as $page) {
            $component = Livewire::actingAs($admin)->test($page);

            if ($page === ListTasks::class) {
                $component->set('activeTab', 'all');
            }

            $html = $component->html();
            $menus = preg_match_all('/x-float\.placement\.[\w-]+(?:\.flip)?(\.teleport)?\.offset=/', $html, $matches);

            $this->assertGreaterThan(0, $menus, "{$page} renders no row menu to check");
            $this->assertNotContains('', $matches[1], "{$page} renders a row menu the bounded table container can clip");
        }
    }

    #[Test]
    public function a_row_whose_menu_is_open_rises_above_the_sticky_header(): void
    {
        $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

        $this->assertSame(1, preg_match(
            '/\.fi-ta-table > thead > tr:last-child > th,\s*\.fi-ta-table > thead > tr:last-child > td \{[^}]*?z-index:\s*(\d+);/',
            $css,
            $header,
        ), 'the sticky header rule (and its z-index) is missing from the theme');

        // One class stronger than the motion layer's `.fi-ta-row:hover` (z-index 2,
        // declared later): while the pointer is on the menu the row is hovered, and
        // an equally specific rule would lose to the later hover rule.
        $this->assertSame(1, preg_match(
            "/\\.fi-ta-table \\.fi-ta-row:has\\(\\[aria-expanded='true'\\]\\) \\{[^}]*?position:\\s*relative;[^}]*?z-index:\\s*(\\d+);/",
            $css,
            $openRow,
        ), 'a row with an open menu is not lifted above the hover rule: the sticky header would cover its menu');

        $this->assertGreaterThan(
            (int) $header[1],
            (int) $openRow[1],
            'a row with an open menu must stack above the sticky header, or the header covers the menu',
        );
    }

    #[Test]
    public function a_row_whose_menu_is_open_does_not_lift_so_the_container_cannot_clip_its_menu(): void
    {
        // The hover lift is a transform, and a transformed row is the containing block of
        // the menu's fixed-position panel: the menu is then trapped in the row and the
        // bounded table container cuts off a menu that opened upward (the owner's second
        // screenshot, 2026-09-28 — the stacking fix alone did not cover it). The open row
        // drops the transform and its transition, one class stronger than the hover rule,
        // inside the motion layer's no-preference guard (MotionLayerTest).
        $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));
        $motion = substr($css, (int) strpos($css, '@media (prefers-reduced-motion: no-preference)'));

        $hover = strpos($motion, '.fi-ta-row:hover {');
        $this->assertNotFalse($hover, 'the motion layer no longer lifts hovered rows — revisit this guard');

        $this->assertSame(1, preg_match(
            "/\\.fi-ta-table \\.fi-ta-row:has\\(\\[aria-expanded='true'\\]\\) \\{[^}]*?transform:\\s*none;[^}]*?transition:\\s*none;/",
            substr($motion, $hover),
            $matches,
        ), 'a row with an open menu still lifts: its transform traps the menu inside the row, and the table container clips it');
    }
}
