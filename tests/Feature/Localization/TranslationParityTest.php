<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two locales must carry the same keys (D-5, CLAUDE.md section 3).
 *
 * A key present in one locale and absent from the other renders its raw key
 * path to whoever reads in the locale that lacks it. JSON language files are
 * outside this guarantee, so they are forbidden.
 */
final class TranslationParityTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function keys(string $locale): array
    {
        $flat = [];

        foreach (glob(lang_path($locale.'/*.php')) ?: [] as $file) {
            $flat += $this->flatten(require $file, basename($file, '.php'));
        }

        return $flat;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = (string) $value;
        }

        return $flat;
    }

    #[Test]
    public function every_arabic_key_has_an_english_counterpart(): void
    {
        $missing = array_keys(array_diff_key($this->keys('ar'), $this->keys('en')));

        $this->assertSame([], $missing, 'missing from lang/en: '.implode(', ', $missing));
    }

    #[Test]
    public function every_english_key_has_an_arabic_counterpart(): void
    {
        $missing = array_keys(array_diff_key($this->keys('en'), $this->keys('ar')));

        $this->assertSame([], $missing, 'missing from lang/ar: '.implode(', ', $missing));
    }

    #[Test]
    public function no_arabic_text_was_left_in_the_english_files(): void
    {
        $copied = [];

        foreach ($this->keys('en') as $key => $value) {
            if (preg_match('/\p{Arabic}/u', $value) === 1) {
                $copied[] = $key;
            }
        }

        $this->assertSame([], $copied, 'still Arabic in lang/en: '.implode(', ', $copied));
    }

    #[Test]
    public function every_configured_locale_has_a_language_directory_and_no_json_files_exist(): void
    {
        foreach ((array) config('app.locales') as $locale) {
            $this->assertDirectoryExists(lang_path($locale));
        }

        $this->assertSame([], glob(lang_path('*.json')) ?: [], 'JSON language files bypass the parity guarantee');
    }
}
