<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base test case.
 *
 * Every lazy relation load throws while the suite runs (plan section 8), so
 * an N+1 introduced on any page, widget, service or job fails the test that
 * exercises it rather than slipping into production. The application itself
 * keeps lazy loading allowed; Tests\Feature\Qa\StructuralGuardsTest asserts
 * the switch is on.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Model::preventLazyLoading();
    }
}
