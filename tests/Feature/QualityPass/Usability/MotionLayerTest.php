<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Usability;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The «روح» motion layer (A-9, amended 2026-09-21): choreography the owner
 * chose, guarded here for the two properties that are contracts rather than
 * taste.
 *
 * 1. Accessibility: every animation and transition the theme adds must sit
 *    behind `prefers-reduced-motion: no-preference`, and the count-up script
 *    must bail for a reduced-motion reader. A vestibular-disorder user gets
 *    a perfectly still panel.
 * 2. Delivery: the count-up rides every panel page through the BODY_END
 *    render hook — a silently dropped hook would remove the behaviour with
 *    no failing test anywhere else.
 */
final class MotionLayerTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function the_count_up_script_rides_every_panel_page_and_respects_reduced_motion(): void
    {
        $this->seedAccess();

        $html = $this->actingAs($this->superAdmin())->get('/admin')->getContent() ?: '';

        $this->assertStringContainsString(
            'fi-wi-stats-overview-stat-value:not([data-crm-counted])',
            $html,
            'the motion partial no longer rides the panel pages - the BODY_END render hook was dropped',
        );

        $this->assertStringContainsString(
            'prefers-reduced-motion: reduce',
            $html,
            'the count-up lost its reduced-motion bail-out',
        );

        // Entrances play on ARRIVAL only: the settle switch turns every
        // entrance rule off after the first choreography, so Livewire
        // updates render instantly. Losing either half silently brings back
        // the regression the owner reported — the entrance replaying on
        // every keystroke, delaying fast data by ~0.7 s of theatrics.
        $this->assertStringContainsString(
            "classList.add('crm-settled')",
            $html,
            'the settle switch left the motion partial - entrances would replay on every Livewire update',
        );

        $this->assertStringContainsString(
            'html:not(.crm-settled)',
            (string) file_get_contents(resource_path('css/filament/admin/theme.css')),
            'the entrance rules lost their settle scope - they would replay on every Livewire update',
        );
    }

    #[Test]
    public function every_motion_rule_in_the_theme_sits_behind_the_no_preference_media_query(): void
    {
        $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

        // Split the sheet at the motion guard: everything animated must come
        // after it. Before the guard, the theme may declare `animation:`/
        // `transition:` nowhere except the reduced-motion kill switch, whose
        // zeroed durations are the opposite of motion.
        $guard = '@media (prefers-reduced-motion: no-preference)';

        $this->assertStringContainsString($guard, $css, 'the motion layer lost its no-preference guard');

        $beforeGuard = substr($css, 0, (int) strpos($css, $guard));

        foreach (['animation:', '@keyframes'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $beforeGuard,
                "an [{$needle}] rule sits outside the prefers-reduced-motion guard - a reduced-motion reader would still see it move",
            );
        }

        // Transitions outside the guard are tolerated only inside the
        // reduced-motion kill switch, which zeroes their durations.
        $transitionsOutside = substr_count($beforeGuard, 'transition:');
        $killSwitchZeroes = substr_count($beforeGuard, 'transition-duration: 0s');

        $this->assertSame(
            0,
            $transitionsOutside,
            'a transition rule sits outside the no-preference guard and outside the kill switch',
        );

        $this->assertGreaterThan(0, $killSwitchZeroes, 'the reduced-motion kill switch disappeared from the theme');
    }
}
