<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Schema;

use App\Support\Database\EnumCheck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Probe: CLAUDE.md section 3 — "code-enum columns string(32) +
 * EnumCheck::apply()". Every attribute a model casts to a backed enum must be
 * guarded by the matching CHECK constraint, and that constraint must accept
 * exactly the enum's cases.
 */
final class EnumCheckCoverageProbeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function every_enum_cast_column_has_a_check_constraint_listing_exactly_its_cases(): void
    {
        $checks = collect(DB::select(
            'SELECT tc.TABLE_NAME AS table_name, cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS clause
               FROM information_schema.CHECK_CONSTRAINTS cc
               JOIN information_schema.TABLE_CONSTRAINTS tc
                 ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
              WHERE cc.CONSTRAINT_SCHEMA = DATABASE()'
        ))->keyBy('name');

        $missing = [];
        $drift = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || (new ReflectionClass($class))->isAbstract() || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            /** @var Model $model */
            $model = new $class;
            $method = new ReflectionMethod($model, 'casts');
            $casts = $method->invoke($model);

            foreach (is_array($casts) ? $casts : [] as $column => $cast) {
                if (! is_string($column) || ! is_string($cast) || ! enum_exists($cast) || ! is_subclass_of($cast, \BackedEnum::class)) {
                    continue;
                }

                $name = EnumCheck::name($model->getTable(), $column);
                $check = $checks->get($name);

                if ($check === null) {
                    $missing[] = "{$model->getTable()}.{$column} ({$cast})";

                    continue;
                }

                // MySQL 8.4 reports string literals escaped inside CHECK_CLAUSE
                // (`kind` in (_utf8mb4\'call\',…)), so unescape before comparing.
                $clause = str_replace("\\'", "'", (string) $check->clause);

                foreach ($cast::cases() as $case) {
                    if (! str_contains($clause, "'".$case->value."'")) {
                        $drift[] = "{$name} lacks '{$case->value}'";
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Enum-cast columns without an EnumCheck constraint.');
        $this->assertSame([], $drift, 'EnumCheck constraints that do not list every case.');
    }
}
