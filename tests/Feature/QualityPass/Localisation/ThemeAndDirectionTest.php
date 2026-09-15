<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Localisation;

use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Theme and direction probes (CLAUDE.md section 3 "Theme", plan 3.4/3.5).
 */
final class ThemeAndDirectionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const THEME = 'resources/css/filament/admin/theme.css';

    #[Test]
    public function dark_mode_gray_overrides_use_the_colour_format_filament_5_consumes(): void
    {
        // Filament 5 emits --gray-950: oklch(...) and paints with var(--gray-950)
        // directly; a bare "15 21 28" triplet (the Filament 3 rgb() format) is an
        // invalid colour, so every dark:bg-gray-900/950 and dark:border-gray-700/800
        // surface computes to its initial value (transparent) in dark mode.
        $this->assertStringStartsWith('oklch(', (string) Color::Slate[950], 'sanity: Filament 5 colour shades are oklch()');

        $css = (string) file_get_contents(base_path(self::THEME));
        preg_match_all('/--gray-(\d+)\s*:\s*([^;]+);/', $css, $matches, PREG_SET_ORDER);

        $invalid = [];

        foreach ($matches as [, $shade, $value]) {
            if (preg_match('/^\s*(oklch|rgb|rgba|hsl|color-mix|var)\(|^\s*#[0-9a-f]{3,8}\s*$/i', $value) !== 1) {
                $invalid[] = "--gray-{$shade}: {$value}";
            }
        }

        $this->assertNotSame([], $matches, 'sanity: the theme overrides gray shades');
        $this->assertSame([], $invalid, "invalid gray overrides in the theme:\n".implode("\n", $invalid));
    }

    #[Test]
    public function the_theme_draws_no_physical_left_or_right_edge(): void
    {
        $css = (string) file_get_contents(base_path(self::THEME));

        // An inset box-shadow with a horizontal offset always lands on the left
        // edge; in RTL the active sidebar marker sits on the far side of the item.
        $this->assertDoesNotMatchRegularExpression('/box-shadow:\s*inset\s+-?[1-9]\d*px\s+0/', $css, 'sidebar active marker is a physical left-edge shadow');
    }

    #[Test]
    public function forward_actions_do_not_use_an_arrow_that_points_backwards_in_rtl(): void
    {
        // Heroicons are not mirrored under dir="rtl": "convert" (a forward step)
        // drawn as an arrow pointing right points back to the start in Arabic.
        $source = (string) file_get_contents(base_path('app/Filament/Support/LeadConversionActions.php'));

        $this->assertStringNotContainsString('Heroicon::OutlinedArrowRightCircle', $source, 'the convert action uses a right-pointing arrow with no RTL mirror');
    }

    #[Test]
    public function no_hex_colour_lives_outside_the_documented_token_blocks(): void
    {
        $css = (string) file_get_contents(base_path(self::THEME));
        $offenders = [];

        foreach (preg_split('/(?=^[^\s@\/*][^{\n]*\{)/m', $css) ?: [] as $block) {
            $selector = trim((string) strstr($block, '{', true));

            if (in_array($selector, [':root', '.dark', '@theme'], true)) {
                continue;
            }

            if (preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $block, $hex) > 0) {
                $offenders[] = "{$selector}: ".implode(', ', $hex[0]);
            }
        }

        $this->assertSame([], $offenders, "hex colours outside :root/.dark/@theme:\n".implode("\n", $offenders));
    }

    #[Test]
    public function list_separators_are_not_hardcoded_in_one_language(): void
    {
        $offenders = [];

        foreach ([
            'app/Filament/Support/DuplicateWarning.php' => "implode('، '",
            'app/Filament/Resources/Deals/Schemas/DealInfolist.php' => ").', '.trans_choice(",
            'app/Filament/Support/EmailActions.php' => "implode(', ', \$rendered->unknownTags)",
        ] as $file => $needle) {
            if (str_contains((string) file_get_contents(base_path($file)), $needle)) {
                $offenders[] = "{$file}: {$needle}";
            }
        }

        $this->assertSame([], $offenders, "separators fixed to one language (Arabic «،» shown in English, «, » shown in Arabic):\n".implode("\n", $offenders));
    }
}
