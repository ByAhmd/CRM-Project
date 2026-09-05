<?php

declare(strict_types=1);

namespace Tests\Feature\Qa;

use App\Contracts\OwnedRecord;
use App\Filament\Concerns\ScopesQueriesToVisibleRecords;
use Filament\Facades\Filament;
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

    #[Test]
    public function every_class_under_app_is_final_unless_it_is_abstract(): void
    {
        $offenders = [];

        foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
            $source = $file->getContents();

            if (preg_match('/^(abstract |final )?class\s/m', $source, $match) !== 1) {
                continue; // trait, interface, enum
            }

            if (($match[1] ?? '') === '') {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'non-final classes: '.implode(', ', $offenders));
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
