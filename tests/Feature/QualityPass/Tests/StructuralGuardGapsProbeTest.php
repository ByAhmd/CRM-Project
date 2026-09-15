<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Tests;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Test-suite audit probe: the structural guards the plan (section 6) and
 * CLAUDE.md section 3 promise but tests/Feature/Qa/StructuralGuardsTest.php
 * does not implement.
 */
final class StructuralGuardGapsProbeTest extends TestCase
{
    #[Test]
    public function every_named_class_under_database_and_tests_is_final_unless_it_is_abstract(): void
    {
        $offenders = [];

        foreach (Finder::create()->files()->in([base_path('database'), base_path('tests')])->name('*.php') as $file) {
            if (preg_match('/^(abstract |final )?(readonly )?class\s/m', $file->getContents(), $match) !== 1) {
                continue; // anonymous migration, trait, interface, enum
            }

            if (($match[1] ?? '') === '') {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'non-final classes: '.implode(', ', $offenders));
    }

    #[Test]
    public function every_policy_defines_the_bulk_abilities_and_refuses_permanent_deletion(): void
    {
        $offenders = [];

        foreach (Finder::create()->files()->in(app_path('Policies'))->depth(0)->name('*Policy.php') as $file) {
            $class = 'App\\Policies\\'.$file->getBasename('.php');

            foreach (['viewAny', 'deleteAny', 'restoreAny', 'forceDelete', 'forceDeleteAny'] as $method) {
                if (! method_exists($class, $method)) {
                    $offenders[] = "{$class}::{$method}";
                }
            }
        }

        $this->assertSame([], $offenders, 'policies without an explicit ability: '.implode(', ', $offenders));
    }

    #[Test]
    public function every_model_with_business_meaning_logs_an_explicit_whitelist(): void
    {
        // CLAUDE.md section 3 "Audit": LogsActivity with an explicit logOnly whitelist.
        $offenders = [];

        foreach (Finder::create()->files()->in(app_path('Models'))->depth(0)->name('*.php') as $file) {
            $source = $file->getContents();

            if (str_contains($source, 'use LogsActivity') && (str_contains($source, 'logAll()') || ! str_contains($source, 'logOnly('))) {
                $offenders[] = $file->getBasename();
            }
        }

        $this->assertSame([], $offenders, 'models logging without a whitelist: '.implode(', ', $offenders));
    }

    #[Test]
    public function no_fixture_helper_is_copied_into_three_or_more_test_classes(): void
    {
        // CLAUDE.md section 3: fixtures come from Tests\Concerns\CreatesCrmFixtures.
        $definitions = [];

        foreach (Finder::create()->files()->in(base_path('tests/Feature'))->exclude('QualityPass')->name('*Test.php') as $file) {
            // Only byte-identical helpers count: same name, same signature, same body.
            preg_match_all('/    private function ([a-zA-Z]+)\(.*?\n    }\n/s', $file->getContents(), $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $definitions[$match[1].'#'.md5($match[0])][] = $file->getRelativePathname();
            }
        }

        $copied = array_filter($definitions, static fn (array $files): bool => count($files) >= 3);
        ksort($copied);

        $this->assertSame([], array_map(static fn (array $files): int => count($files), $copied), 'helpers to move into CreatesCrmFixtures: '.implode(', ', array_keys($copied)));
    }

    #[Test]
    public function lazy_loading_is_prevented_while_the_suite_runs(): void
    {
        // Plan section 8: "preventLazyLoading in tests so N+1s fail the suite"; section 7:
        // Model::shouldBeStrict() outside production. Today it is opt-in through an
        // uncommitted CRM_PREVENT_LAZY_LOADING switch in tests/TestCase.php that CI never sets.
        $this->assertTrue(Model::preventsLazyLoading(), 'lazy loading is allowed in the testing environment');
    }
}
