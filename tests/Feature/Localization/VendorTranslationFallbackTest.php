<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A missing vendor translation must never render its own key path.
 *
 * Filament's Arabic packs are not complete; the keys missing at the installed
 * version are overridden under lang/vendor and the fallback locale is English,
 * so the worst case is a readable English string, never "filament-forms::…".
 */
final class VendorTranslationFallbackTest extends TestCase
{
    #[Test]
    public function the_overridden_form_keys_resolve_in_arabic(): void
    {
        app()->setLocale('ar');

        foreach ([
            'filament-forms::components.select.actions.clear.label',
            'filament-forms::components.select.actions.remove_option.label',
            'filament-forms::components.select.search_label',
        ] as $key) {
            $rendered = __($key);

            $this->assertNotSame($key, $rendered, "{$key} did not resolve");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $rendered, "{$key} resolved but not to Arabic");
        }
    }

    #[Test]
    public function every_filament_english_key_resolves_to_something_readable_in_arabic(): void
    {
        app()->setLocale('ar');

        $unresolved = [];

        foreach ($this->filamentPackages() as $namespace => $directory) {
            foreach (glob($directory.'/resources/lang/en/*.php') ?: [] as $file) {
                $group = basename($file, '.php');

                foreach ($this->flatten(require $file) as $key => $value) {
                    if (! is_string($value)) {
                        continue;
                    }

                    $full = "{$namespace}::{$group}.{$key}";

                    if (__($full) === $full) {
                        $unresolved[] = $full;
                    }
                }
            }
        }

        $this->assertSame([], $unresolved, 'keys rendering their own path in Arabic: '.implode(', ', array_slice($unresolved, 0, 20)));
    }

    /**
     * @return array<string, string>
     */
    private function filamentPackages(): array
    {
        return [
            'filament-panels' => base_path('vendor/filament/filament'),
            'filament-actions' => base_path('vendor/filament/actions'),
            'filament-forms' => base_path('vendor/filament/forms'),
            'filament-tables' => base_path('vendor/filament/tables'),
            'filament-notifications' => base_path('vendor/filament/notifications'),
            'filament-infolists' => base_path('vendor/filament/infolists'),
            'filament-schemas' => base_path('vendor/filament/schemas'),
            'filament-widgets' => base_path('vendor/filament/widgets'),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }
}
