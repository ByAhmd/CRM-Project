<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Tests;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test-suite audit probe (A-13, plan section 6 "Gates"): CI runs what
 * `composer check` runs, on the production floor, and a warning, a risky test
 * or a PHP deprecation that only the 8.3 runner raises fails the build instead
 * of scrolling past.
 */
final class ContinuousIntegrationParityProbeTest extends TestCase
{
    #[Test]
    public function the_workflow_lints_analyses_builds_and_tests_on_php_8_3_and_mysql_8_4(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertMatchesRegularExpression("/php-version:\\s*'8\\.3'/", $workflow);
        $this->assertStringContainsString('image: mysql:8.4', $workflow);

        $steps = ['vendor/bin/pint --test', 'vendor/bin/phpstan analyse', 'npm run build', 'vendor/bin/phpunit'];
        $positions = array_map(static fn (string $step): int|false => strpos($workflow, $step), $steps);

        $this->assertNotContains(false, $positions, 'a composer check step is missing from CI');
        $this->assertLessThan($positions[3], $positions[2], 'assets must be built before PHPUnit');
    }

    #[Test]
    public function phpunit_fails_on_warnings_risky_tests_and_deprecations(): void
    {
        $config = simplexml_load_file(base_path('phpunit.xml'));
        $this->assertNotFalse($config);

        foreach (['failOnWarning', 'failOnRisky', 'failOnDeprecation', 'failOnPhpunitDeprecation'] as $attribute) {
            $this->assertSame('true', (string) $config[$attribute], "phpunit.xml does not set {$attribute}=\"true\"");
        }
    }
}
