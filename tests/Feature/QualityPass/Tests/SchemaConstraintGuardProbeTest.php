<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Tests;

use App\Support\Database\EnumCheck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Test-suite audit probe (plan section 6, "Database: information_schema
 * assertions for FKs/uniques/CHECKs"): no test under tests/Feature reads
 * information_schema today. These guards pin the schema conventions of
 * CLAUDE.md section 3 so a later migration cannot silently drop them.
 */
final class SchemaConstraintGuardProbeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_code_enum_cast_is_backed_by_a_check_constraint_listing_exactly_its_cases(): void
    {
        $problems = [];

        foreach ($this->models() as $model) {
            $table = $model->getTable();

            foreach ($model->getCasts() as $column => $cast) {
                $enum = explode(':', (string) $cast)[0];

                if (! enum_exists($enum) || ! is_subclass_of($enum, \BackedEnum::class) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $clause = DB::table('information_schema.CHECK_CONSTRAINTS')
                    ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                    ->where('CONSTRAINT_NAME', EnumCheck::name($table, $column))
                    ->value('CHECK_CLAUSE');

                if ($clause === null) {
                    $problems[] = "{$table}.{$column} has no CHECK";

                    continue;
                }

                preg_match_all("/_utf8mb4\\\\'([^\\\\']*)\\\\'|'([^']*)'/", (string) $clause, $matches);
                $listed = array_values(array_filter(array_merge($matches[1], $matches[2]), static fn (string $v): bool => $v !== ''));
                $cases = array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases());
                sort($listed);
                sort($cases);

                if ($listed !== $cases) {
                    $problems[] = "{$table}.{$column} CHECK lists [".implode(',', $listed).'] but '.$enum.' has ['.implode(',', $cases).']';
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    #[Test]
    public function every_foreign_key_declares_its_delete_rule_and_every_owner_column_is_indexed(): void
    {
        $database = DB::getDatabaseName();
        $problems = [];

        $ownerColumns = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $database)
            ->whereIn('COLUMN_NAME', ['owner_id', 'assignee_id', 'status'])
            ->get(['TABLE_NAME', 'COLUMN_NAME']);

        foreach ($ownerColumns as $column) {
            $indexed = DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', $database)
                ->where('TABLE_NAME', $column->TABLE_NAME)
                ->where('COLUMN_NAME', $column->COLUMN_NAME)
                ->where('SEQ_IN_INDEX', 1)
                ->exists();

            if (! $indexed && ! in_array($column->TABLE_NAME, ['jobs', 'failed_jobs', 'job_batches'], true)) {
                $problems[] = "{$column->TABLE_NAME}.{$column->COLUMN_NAME} is not the leading column of any index";
            }
        }

        $userReferences = DB::table('information_schema.REFERENTIAL_CONSTRAINTS as r')
            ->join('information_schema.KEY_COLUMN_USAGE as k', function ($join): void {
                $join->on('k.CONSTRAINT_NAME', '=', 'r.CONSTRAINT_NAME')->on('k.CONSTRAINT_SCHEMA', '=', 'r.CONSTRAINT_SCHEMA');
            })
            ->where('r.CONSTRAINT_SCHEMA', $database)
            ->where('r.REFERENCED_TABLE_NAME', 'users')
            ->whereIn('k.COLUMN_NAME', ['owner_id', 'created_by'])
            ->get(['k.TABLE_NAME', 'k.COLUMN_NAME', 'r.DELETE_RULE']);

        foreach ($userReferences as $reference) {
            if ($reference->DELETE_RULE !== 'SET NULL') {
                $problems[] = "{$reference->TABLE_NAME}.{$reference->COLUMN_NAME} → users is {$reference->DELETE_RULE}, DATABASE_DESIGN requires SET NULL";
            }
        }

        $this->assertNotEmpty($userReferences, 'precondition: owner foreign keys exist');
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * @return iterable<Model>
     */
    private function models(): iterable
    {
        foreach (Finder::create()->files()->in(app_path('Models'))->depth(0)->name('*.php') as $file) {
            $class = 'App\\Models\\'.$file->getBasename('.php');

            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                yield new $class;
            }
        }
    }
}
