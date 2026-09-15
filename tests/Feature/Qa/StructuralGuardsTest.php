<?php

declare(strict_types=1);

namespace Tests\Feature\Qa;

use App\Contracts\OwnedRecord;
use App\Filament\Concerns\ScopesQueriesToVisibleRecords;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * Conventions from CLAUDE.md section 3 that a reviewer would otherwise have to
 * check by hand on every change.
 */
final class StructuralGuardsTest extends TestCase
{
    #[Test]
    public function every_project_php_file_declares_strict_types(): void
    {
        $missing = [];

        foreach ($this->projectFiles() as $file) {
            if (! str_contains($file->getContents(), 'declare(strict_types=1);')) {
                $missing[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $missing, 'files without declare(strict_types=1): '.implode(', ', $missing));
    }

    /**
     * CLAUDE.md section 3: models, services, enums, policies, resources, pages,
     * tests, migrations and seeders are final. Anonymous migrations have no
     * named class and are skipped with traits, interfaces and enums.
     */
    #[Test]
    public function every_named_class_under_app_database_and_tests_is_final_unless_it_is_abstract(): void
    {
        $offenders = [];

        foreach (Finder::create()->files()->in([base_path('app'), base_path('database'), base_path('tests')])->name('*.php') as $file) {
            $source = $file->getContents();

            if (preg_match('/^(abstract |final )?(readonly )?class\s/m', $source, $match) !== 1) {
                continue; // anonymous migration, trait, interface, enum
            }

            if (($match[1] ?? '') === '') {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'non-final classes: '.implode(', ', $offenders));
    }

    /**
     * The bulk and permanent-deletion abilities are explicit on every policy:
     * Filament's authorisation helper allows an ability a policy does not
     * define, and strict authorisation throws on one. Permanent deletion is
     * never granted (D-13).
     */
    #[Test]
    public function every_policy_defines_the_bulk_abilities_and_refuses_permanent_deletion(): void
    {
        $missing = [];
        $granted = [];

        foreach (Finder::create()->files()->in(app_path('Policies'))->depth(0)->name('*Policy.php') as $file) {
            $class = 'App\\Policies\\'.$file->getBasename('.php');

            foreach (['viewAny', 'deleteAny', 'restoreAny', 'forceDelete', 'forceDeleteAny'] as $method) {
                if (! method_exists($class, $method)) {
                    $missing[] = "{$class}::{$method}";
                }
            }

            if (method_exists($class, 'forceDeleteAny') && app($class)->forceDeleteAny(new User) !== false) {
                $granted[] = $class.'::forceDeleteAny';
            }
        }

        $this->assertSame([], $missing, 'policies without an explicit ability: '.implode(', ', $missing));
        $this->assertSame([], $granted, 'policies granting permanent deletion: '.implode(', ', $granted));
    }

    #[Test]
    public function lazy_loading_is_prevented_while_the_suite_runs(): void
    {
        // Plan section 8: an N+1 fails the suite instead of reaching production.
        $this->assertTrue(Model::preventsLazyLoading(), 'lazy loading is allowed in the testing environment');
    }

    #[Test]
    public function every_resource_model_has_a_policy_and_owned_models_scope_their_queries(): void
    {
        Filament::setCurrentPanel('admin');

        $withoutPolicy = [];
        $unscoped = [];

        foreach (Filament::getResources() as $resource) {
            $model = $resource::getModel();

            if (Gate::getPolicyFor($model) === null) {
                $withoutPolicy[] = $resource;
            }

            if (is_subclass_of($model, OwnedRecord::class)
                && ! in_array(ScopesQueriesToVisibleRecords::class, class_uses_recursive($resource), true)) {
                $unscoped[] = $resource;
            }
        }

        $this->assertSame([], $withoutPolicy, 'resources whose model has no policy: '.implode(', ', $withoutPolicy));
        $this->assertSame([], $unscoped, 'owned-record resources without ScopesQueriesToVisibleRecords: '.implode(', ', $unscoped));
    }

    #[Test]
    public function every_table_declares_a_default_sort(): void
    {
        $missing = [];

        foreach (Finder::create()->files()->in(base_path('app/Filament'))->path('Tables')->name('*Table.php') as $file) {
            if (! str_contains($file->getContents(), '->defaultSort(')) {
                $missing[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $missing, 'tables without defaultSort(): '.implode(', ', $missing));
    }

    #[Test]
    public function no_filament_class_carries_a_hardcoded_user_facing_label(): void
    {
        $literal = [];

        foreach (Finder::create()->files()->in(base_path('app/Filament'))->name('*.php') as $file) {
            if (preg_match('/->(label|placeholder|helperText|heading|description|title|modalHeading|modalDescription|emptyStateHeading|emptyStateDescription)\(\s*[\'"][^\'"]+[\'"]\s*\)/', $file->getContents(), $match) === 1) {
                $literal[] = $file->getRelativePathname().': '.$match[0];
            }

            // Import example values reach every user in the example CSV: machine values (keys,
            // codes, e-mail addresses, URLs, digits) stay literal, words in any language do not.
            preg_match_all('/->example\(\s*[\'"]([^\'"]*)[\'"]\s*\)/u', $file->getContents(), $examples, PREG_SET_ORDER);

            foreach ($examples as $example) {
                if (preg_match('/\p{Arabic}|[A-Za-z]+\s+[A-Za-z]+/u', $example[1]) === 1) {
                    $literal[] = $file->getRelativePathname().': '.$example[0];
                }
            }
        }

        $this->assertSame([], $literal, 'hardcoded strings: '.implode('; ', $literal));
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function projectFiles(): iterable
    {
        return Finder::create()
            ->files()
            ->in([base_path('app'), base_path('database'), base_path('tests'), base_path('routes'), base_path('lang'), base_path('bootstrap')])
            ->exclude('cache')
            ->name('*.php');
    }
}
