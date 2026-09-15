<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Enums\ActivityLogEvent;
use App\Enums\NavigationGroup;
use App\Enums\Permission;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Spatie\Activitylog\Traits\LogsActivity;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Cross-references every translation key the code asks for with the keys the
 * lang/{ar,en} files define (quality pass, plan step 12 "translation audit").
 *
 * MISSING keys render their raw path to the reader: they fail the suite.
 * DEAD keys are defined and never read: they fail with the list so they are
 * deleted instead of drifting.
 *
 * Keys consumed dynamically are excluded from the dead-key report:
 *  - framework groups read by Laravel itself: validation, auth, passwords, pagination;
 *  - every key starting with a prefix that the code completes by concatenation
 *    (__('enums.task_status.'.$value), __('reports.pages.'.$key.'.title'), ...);
 *    those prefixes are discovered from the source, so a new one needs no edit here;
 *  - activity.events (read as one array by ActivityLogEvent::getLabel());
 *  - any key whose full path appears as a string literal anywhere in the scanned
 *    code (key maps handed to __() later, e.g. email merge tags).
 * The dynamic prefixes are instead checked by enumerating their real inputs
 * (enum cases, audited models and attributes, log names).
 */
final class LangKeyUsageTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const SCANNED = ['app', 'resources/views', 'routes', 'database/seeders'];

    private const FRAMEWORK_GROUPS = ['validation', 'auth', 'passwords', 'pagination'];

    private const ARRAY_CONSUMED = ['activity.events.'];

    #[Test]
    public function every_literal_translation_key_used_in_code_is_defined_in_both_locales(): void
    {
        $defined = ['ar' => $this->keys('ar'), 'en' => $this->keys('en')];
        $groups = array_map(static fn (string $file): string => basename($file, '.php'), glob(lang_path('en/*.php')) ?: []);
        $missing = [];

        foreach ($this->usages()['literal'] as $key => $files) {
            if (str_contains($key, '::') || ! str_contains($key, '.')) {
                continue;
            }

            if (! in_array(explode('.', $key)[0], $groups, true)) {
                $missing[] = "{$key} (no lang group; ".implode(', ', $files).')';

                continue;
            }

            foreach (['ar', 'en'] as $locale) {
                if (! array_key_exists($key, $defined[$locale]) && ! is_array(__($key, [], $locale))) {
                    $missing[] = "{$key} missing in {$locale} (".implode(', ', $files).')';
                }
            }
        }

        $this->assertSame([], $missing, "translation keys used but not defined:\n".implode("\n", $missing));
    }

    #[Test]
    public function every_dynamically_built_translation_key_resolves_in_both_locales(): void
    {
        $unresolved = [];

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ($this->dynamicKeys() as $key) {
                if (__($key, [], $locale) === $key) {
                    $unresolved[] = "{$locale}: {$key}";
                }
            }

            foreach ($this->enumClasses() as $enum) {
                foreach ($enum::cases() as $case) {
                    $label = $case->getLabel();

                    if (! is_string($label) || preg_match('/^[a-z_]+\.[a-z0-9_.]+$/', $label) === 1) {
                        $unresolved[] = "{$locale}: {$enum}::{$case->name} label ".var_export($label, true);
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($unresolved)), "dynamic translation keys rendering their own path:\n".implode("\n", array_unique($unresolved)));
    }

    #[Test]
    public function no_translation_key_is_defined_without_being_used(): void
    {
        $usages = $this->usages();
        $prefixes = [...array_keys($usages['prefixes']), ...self::ARRAY_CONSUMED];
        $dead = [];

        foreach ($this->keys('en') as $key => $value) {
            if (in_array(explode('.', $key)[0], self::FRAMEWORK_GROUPS, true)) {
                continue;
            }

            if (isset($usages['literal'][$key]) || isset($usages['strings'][$key])) {
                continue;
            }

            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    continue 2;
                }
            }

            $parts = explode('.', $key);

            while (count($parts) > 1) {
                array_pop($parts);
                $parent = implode('.', $parts);

                if (isset($usages['literal'][$parent]) || isset($usages['strings'][$parent])) {
                    continue 2;
                }
            }

            $dead[] = $key;
        }

        $this->assertSame([], $dead, 'translation keys defined but never used (exclusions: framework groups '.implode('/', self::FRAMEWORK_GROUPS).', dynamic prefixes '.implode(', ', $prefixes)."):\n".implode("\n", $dead));
    }

    /**
     * @return list<string>
     */
    private function dynamicKeys(): array
    {
        $keys = [];

        foreach (ActivityLogEvent::logNames() as $logName) {
            $keys[] = 'activity.log_names.'.$logName;
        }

        foreach (NavigationGroup::cases() as $group) {
            $keys[] = 'navigation.'.$group->value;
        }

        foreach (Permission::cases() as $permission) {
            $keys[] = 'permissions.groups.'.$permission->group();
            $keys[] = 'permissions.verbs.'.$permission->verb();
        }

        foreach ($this->auditedModels() as $class) {
            $keys[] = 'activity.subject_types.'.class_basename($class);

            $model = new $class;

            if (! method_exists($model, 'getActivitylogOptions')) {
                continue;
            }

            foreach ($model->getActivitylogOptions()->logAttributes as $attribute) {
                $keys[] = 'activity.attributes.'.$attribute;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<class-string<Model>>
     */
    private function auditedModels(): array
    {
        $models = [];

        foreach (Finder::create()->files()->in(app_path('Models'))->depth(0)->name('*.php') as $file) {
            $class = 'App\\Models\\'.$file->getBasename('.php');

            if (class_exists($class) && in_array(LogsActivity::class, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }

        return $models;
    }

    /**
     * @return list<class-string>
     */
    private function enumClasses(): array
    {
        $enums = [];

        foreach (Finder::create()->files()->in(app_path('Enums'))->name('*.php') as $file) {
            $class = 'App\\Enums\\'.$file->getBasename('.php');

            if (enum_exists($class) && (new ReflectionClass($class))->implementsInterface(HasLabel::class)) {
                $enums[] = $class;
            }
        }

        return $enums;
    }

    /**
     * @return array{literal: array<string, list<string>>, prefixes: array<string, true>, strings: array<string, true>}
     */
    private function usages(): array
    {
        $literal = [];
        $prefixes = [];
        $strings = [];

        foreach (self::SCANNED as $directory) {
            foreach (Finder::create()->files()->in(base_path($directory))->name('*.php') as $file) {
                $source = $file->getContents();
                $path = $directory.'/'.str_replace('\\', '/', $file->getRelativePathname());

                preg_match_all('/(?:\b__|\btrans_choice|\btrans|@lang|@choice|Lang::get|Lang::choice)\(\s*([\x27\x22])([^\x27\x22$]+?)\1\s*[,)]/', $source, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $literal[$match[2]][] = $path;
                }

                preg_match_all('/(?:\b__|\btrans_choice|\btrans)\(\s*([\x27\x22])([^\x27\x22$]+?)\1\s*\./', $source, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $prefixes[$match[2]] = true;
                }

                preg_match_all('/([\x27\x22])([a-z_]+\.[A-Za-z0-9_.]+)\1/', $source, $matches);

                foreach ($matches[2] as $string) {
                    $strings[$string] = true;
                }
            }
        }

        return [
            'literal' => array_map(static fn (array $files): array => array_values(array_unique($files)), $literal),
            'prefixes' => $prefixes,
            'strings' => $strings,
        ];
    }

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
}
