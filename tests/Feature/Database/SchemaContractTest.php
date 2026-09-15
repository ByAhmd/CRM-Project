<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\LeadScoringRuleKind;
use App\Support\Database\EnumCheck;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The schema contract of DATABASE_DESIGN.md and CLAUDE.md section 3, read
 * back from information_schema (plan section 6, "Database" family) so a later
 * migration cannot silently drop a constraint the application relies on:
 * CHECKs on code-enum columns, indexes on ownership and status columns, the
 * delete rule of every foreign key the design names, the unique keys and the
 * DATETIME moments.
 */
final class SchemaContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Foreign keys to users that delete with the user: rows that exist only
     * for that user (preferences, personal views, Filament import/export runs).
     */
    private const array USER_OWNED_REFERENCES = [
        'exports.user_id',
        'imports.user_id',
        'notification_preferences.user_id',
        'saved_views.user_id',
    ];

    #[Test]
    public function every_code_enum_cast_is_guarded_by_a_check_listing_exactly_its_cases(): void
    {
        $problems = [];
        $checked = 0;

        foreach ($this->models() as $model) {
            $table = $model->getTable();

            foreach ($model->getCasts() as $column => $cast) {
                $enum = explode(':', $cast)[0];

                if (! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $checked++;
                $clause = $this->checkClause(EnumCheck::name($table, $column));

                if ($clause === null) {
                    $problems[] = "{$table}.{$column} ({$enum}) has no CHECK constraint";

                    continue;
                }

                $listed = $this->literals($clause);
                $cases = array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases());
                sort($listed);
                sort($cases);

                if ($listed !== $cases) {
                    $problems[] = sprintf('%s.%s CHECK lists [%s], %s has [%s]', $table, $column, implode(',', $listed), $enum, implode(',', $cases));
                }

                $length = (int) DB::table('information_schema.COLUMNS')
                    ->where('TABLE_SCHEMA', DB::getDatabaseName())
                    ->where('TABLE_NAME', $table)
                    ->where('COLUMN_NAME', $column)
                    ->value('CHARACTER_MAXIMUM_LENGTH');

                if ($length < max(array_map('strlen', $cases))) {
                    $problems[] = "{$table}.{$column} is too short for its longest case";
                }
            }
        }

        $this->assertGreaterThan(20, $checked, 'precondition: the enum casts were discovered');
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    #[Test]
    public function every_owner_assignee_and_status_column_leads_an_index(): void
    {
        $columns = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->whereIn('COLUMN_NAME', ['owner_id', 'assignee_id', 'status'])
            ->whereNotIn('TABLE_NAME', ['jobs', 'job_batches', 'failed_jobs'])
            ->get(['TABLE_NAME', 'COLUMN_NAME']);

        $this->assertNotEmpty($columns);

        $unindexed = $columns
            ->reject(fn (object $column): bool => $this->leadsAnIndex((string) $column->TABLE_NAME, (string) $column->COLUMN_NAME))
            ->map(static fn (object $column): string => "{$column->TABLE_NAME}.{$column->COLUMN_NAME}")
            ->values()
            ->all();

        $this->assertSame([], $unindexed);
    }

    #[Test]
    public function every_reference_to_a_user_is_set_null_unless_the_row_belongs_to_the_user(): void
    {
        $references = $this->foreignKeys()->filter(static fn (object $key): bool => $key->referenced === 'users');

        $this->assertNotEmpty($references);

        $wrong = $references
            ->reject(static fn (object $key): bool => $key->rule === (in_array("{$key->table}.{$key->column}", self::USER_OWNED_REFERENCES, true) ? 'CASCADE' : 'SET NULL'))
            ->map(static fn (object $key): string => "{$key->table}.{$key->column} → users is {$key->rule}")
            ->values()
            ->all();

        $this->assertSame([], $wrong);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function designedForeignKeys(): array
    {
        return [
            // Lookups referenced by rows cannot be deleted (section 6).
            'accounts.industry_id' => ['accounts.industry_id', 'industries', 'RESTRICT'],
            'leads.lead_source_id' => ['leads.lead_source_id', 'lead_sources', 'RESTRICT'],
            'leads.lead_status_id' => ['leads.lead_status_id', 'lead_statuses', 'RESTRICT'],
            'deals.lead_source_id' => ['deals.lead_source_id', 'lead_sources', 'RESTRICT'],
            'deals.pipeline_id' => ['deals.pipeline_id', 'pipelines', 'RESTRICT'],
            'deals.stage_id' => ['deals.stage_id', 'pipeline_stages', 'RESTRICT'],
            'deals.close_reason_id' => ['deals.close_reason_id', 'deal_close_reasons', 'RESTRICT'],
            'pipeline_stages.pipeline_id' => ['pipeline_stages.pipeline_id', 'pipelines', 'RESTRICT'],
            'activities.activity_type_id' => ['activities.activity_type_id', 'activity_types', 'RESTRICT'],
            'lead_status_logs.from_status_id' => ['lead_status_logs.from_status_id', 'lead_statuses', 'RESTRICT'],
            'lead_status_logs.to_status_id' => ['lead_status_logs.to_status_id', 'lead_statuses', 'RESTRICT'],
            'deal_stage_logs.from_stage_id' => ['deal_stage_logs.from_stage_id', 'pipeline_stages', 'RESTRICT'],
            'deal_stage_logs.to_stage_id' => ['deal_stage_logs.to_stage_id', 'pipeline_stages', 'RESTRICT'],
            'lead_scoring_rules.lead_source_id' => ['lead_scoring_rules.lead_source_id', 'lead_sources', 'RESTRICT'],
            'lead_scoring_rules.lead_status_id' => ['lead_scoring_rules.lead_status_id', 'lead_statuses', 'RESTRICT'],
            'deal_competitors.competitor_id' => ['deal_competitors.competitor_id', 'competitors', 'RESTRICT'],
            'deal_products.product_id' => ['deal_products.product_id', 'products', 'RESTRICT'],
            // Commercial parents are restricted, not cascaded.
            'contacts.account_id' => ['contacts.account_id', 'accounts', 'RESTRICT'],
            'deals.account_id' => ['deals.account_id', 'accounts', 'RESTRICT'],
            // Owned children cascade with their parent.
            'lead_status_logs.lead_id' => ['lead_status_logs.lead_id', 'leads', 'CASCADE'],
            'deal_stage_logs.deal_id' => ['deal_stage_logs.deal_id', 'deals', 'CASCADE'],
            'deal_contacts.deal_id' => ['deal_contacts.deal_id', 'deals', 'CASCADE'],
            'deal_contacts.contact_id' => ['deal_contacts.contact_id', 'contacts', 'CASCADE'],
            'deal_competitors.deal_id' => ['deal_competitors.deal_id', 'deals', 'CASCADE'],
            'deal_products.deal_id' => ['deal_products.deal_id', 'deals', 'CASCADE'],
            'taggables.tag_id' => ['taggables.tag_id', 'tags', 'CASCADE'],
            'custom_field_values.custom_field_id' => ['custom_field_values.custom_field_id', 'custom_fields', 'CASCADE'],
            // Optional links survive the removal of what they point at.
            'accounts.parent_account_id' => ['accounts.parent_account_id', 'accounts', 'SET NULL'],
            'contacts.lead_id' => ['contacts.lead_id', 'leads', 'SET NULL'],
            'deals.contact_id' => ['deals.contact_id', 'contacts', 'SET NULL'],
            'deals.lead_id' => ['deals.lead_id', 'leads', 'SET NULL'],
            'leads.converted_account_id' => ['leads.converted_account_id', 'accounts', 'SET NULL'],
            'leads.converted_contact_id' => ['leads.converted_contact_id', 'contacts', 'SET NULL'],
            'leads.converted_deal_id' => ['leads.converted_deal_id', 'deals', 'SET NULL'],
            'activities.lead_id' => ['activities.lead_id', 'leads', 'SET NULL'],
            'activities.contact_id' => ['activities.contact_id', 'contacts', 'SET NULL'],
            'activities.account_id' => ['activities.account_id', 'accounts', 'SET NULL'],
            'activities.deal_id' => ['activities.deal_id', 'deals', 'SET NULL'],
            'activities.task_id' => ['activities.task_id', 'tasks', 'SET NULL'],
            'activities.note_id' => ['activities.note_id', 'notes', 'SET NULL'],
            'tasks.lead_id' => ['tasks.lead_id', 'leads', 'SET NULL'],
            'tasks.contact_id' => ['tasks.contact_id', 'contacts', 'SET NULL'],
            'tasks.account_id' => ['tasks.account_id', 'accounts', 'SET NULL'],
            'tasks.deal_id' => ['tasks.deal_id', 'deals', 'SET NULL'],
            'tasks.series_id' => ['tasks.series_id', 'tasks', 'SET NULL'],
            'notes.lead_id' => ['notes.lead_id', 'leads', 'SET NULL'],
            'notes.contact_id' => ['notes.contact_id', 'contacts', 'SET NULL'],
            'notes.account_id' => ['notes.account_id', 'accounts', 'SET NULL'],
            'notes.deal_id' => ['notes.deal_id', 'deals', 'SET NULL'],
            'users.team_id' => ['users.team_id', 'teams', 'SET NULL'],
        ];
    }

    #[Test]
    #[DataProvider('designedForeignKeys')]
    public function a_designed_foreign_key_exists_with_its_delete_rule(string $column, string $referenced, string $rule): void
    {
        [$table, $name] = explode('.', $column);

        $key = $this->foreignKeys()->first(static fn (object $key): bool => $key->table === $table && $key->column === $name);

        $this->assertNotNull($key, "{$column} has no foreign key.");
        $this->assertSame($referenced, $key->referenced, "{$column} references the wrong table.");
        $this->assertSame($rule, $key->rule, "{$column} → {$referenced} has the wrong delete rule.");
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function designedUniqueKeys(): array
    {
        return [
            'users.email' => ['users', ['email']],
            'settings.key' => ['settings', ['key']],
            'teams.name_en' => ['teams', ['name_en']],
            'teams.name_ar' => ['teams', ['name_ar']],
            'lead_sources.name_en' => ['lead_sources', ['name_en']],
            'lead_sources.name_ar' => ['lead_sources', ['name_ar']],
            'lead_statuses.name_en' => ['lead_statuses', ['name_en']],
            'lead_statuses.name_ar' => ['lead_statuses', ['name_ar']],
            'industries.name_en' => ['industries', ['name_en']],
            'industries.name_ar' => ['industries', ['name_ar']],
            'pipelines.name_en' => ['pipelines', ['name_en']],
            'pipelines.name_ar' => ['pipelines', ['name_ar']],
            'pipeline_stages.pipeline_id,name_en' => ['pipeline_stages', ['pipeline_id', 'name_en']],
            'pipeline_stages.pipeline_id,name_ar' => ['pipeline_stages', ['pipeline_id', 'name_ar']],
            'activity_types.name_en' => ['activity_types', ['name_en']],
            'activity_types.name_ar' => ['activity_types', ['name_ar']],
            'deal_close_reasons.kind,name_en' => ['deal_close_reasons', ['kind', 'name_en']],
            'deal_close_reasons.kind,name_ar' => ['deal_close_reasons', ['kind', 'name_ar']],
            'competitors.name' => ['competitors', ['name']],
            'tags.name_en' => ['tags', ['name_en']],
            'tags.name_ar' => ['tags', ['name_ar']],
            'products.code' => ['products', ['code']],
            'email_templates.name_en' => ['email_templates', ['name_en']],
            'email_templates.name_ar' => ['email_templates', ['name_ar']],
            'lead_scoring_rules.rule' => ['lead_scoring_rules', ['kind', 'reference_key', 'field_key', 'within_days_key']],
            'custom_fields.entity,key' => ['custom_fields', ['entity', 'key']],
            'custom_field_values.field,entity' => ['custom_field_values', ['custom_field_id', 'entity_type', 'entity_id']],
            'deal_contacts.deal_id,contact_id' => ['deal_contacts', ['deal_id', 'contact_id']],
            'deal_competitors.deal_id,competitor_id' => ['deal_competitors', ['deal_id', 'competitor_id']],
            'notification_preferences.user_id,event' => ['notification_preferences', ['user_id', 'event']],
            'saved_views.user_id,resource,name' => ['saved_views', ['user_id', 'resource', 'name']],
            'attachments.uuid' => ['attachments', ['uuid']],
        ];
    }

    /**
     * @param  list<string>  $columns
     */
    #[Test]
    #[DataProvider('designedUniqueKeys')]
    public function a_designed_unique_key_exists(string $table, array $columns): void
    {
        $uniques = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('NON_UNIQUE', 0)
            ->where('INDEX_NAME', '<>', 'PRIMARY')
            ->orderBy('SEQ_IN_INDEX')
            ->get(['INDEX_NAME', 'COLUMN_NAME'])
            ->groupBy('INDEX_NAME')
            ->map(static fn ($rows): array => $rows->pluck('COLUMN_NAME')->map(static fn (mixed $name): string => (string) $name)->all());

        $this->assertTrue(
            $uniques->contains(static fn (array $indexed): bool => $indexed === $columns),
            sprintf('%s has no unique key on (%s).', $table, implode(', ', $columns)),
        );
    }

    #[Test]
    public function the_scoring_rule_key_refuses_a_duplicate_even_though_unused_columns_are_null(): void
    {
        $source = DB::table('lead_sources')->insertGetId(['name_ar' => 'معرض', 'name_en' => 'Fair', 'created_at' => now(), 'updated_at' => now()]);
        $rule = [
            'kind' => LeadScoringRuleKind::Source->value,
            'reference_id' => $source,
            'points' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('lead_scoring_rules')->insert($rule);

        $this->expectException(QueryException::class);

        DB::table('lead_scoring_rules')->insert($rule);
    }

    #[Test]
    public function every_business_moment_is_a_datetime_column(): void
    {
        $moments = [
            'leads' => ['scored_at', 'qualified_at', 'converted_at', 'last_activity_at', 'stale_notified_at'],
            'deals' => ['won_at', 'lost_at', 'last_activity_at'],
            'lead_status_logs' => ['changed_at'],
            'deal_stage_logs' => ['changed_at'],
            'notes' => ['edited_at'],
            'tasks' => ['due_at', 'starts_at', 'ends_at', 'completed_at', 'reminder_at', 'reminder_sent_at', 'overdue_notified_at'],
            'activities' => ['occurred_at'],
            'custom_field_values' => ['value_datetime'],
        ];

        $wrong = [];

        foreach ($moments as $table => $columns) {
            foreach ($columns as $column) {
                $type = DB::table('information_schema.COLUMNS')
                    ->where('TABLE_SCHEMA', DB::getDatabaseName())
                    ->where('TABLE_NAME', $table)
                    ->where('COLUMN_NAME', $column)
                    ->value('DATA_TYPE');

                if ($type !== 'datetime') {
                    $wrong[] = "{$table}.{$column} is ".var_export($type, true);
                }
            }
        }

        $this->assertSame([], $wrong);
    }

    /**
     * @return iterable<Model>
     */
    private function models(): iterable
    {
        foreach (Finder::create()->files()->in(app_path('Models'))->depth(0)->name('*.php') as $file) {
            $class = 'App\\Models\\'.$file->getBasename('.php');

            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                yield new $class;
            }
        }
    }

    private function checkClause(string $name): ?string
    {
        $clause = DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('CONSTRAINT_NAME', $name)
            ->value('CHECK_CLAUSE');

        return $clause === null ? null : (string) $clause;
    }

    /**
     * The string literals of a CHECK clause; MySQL 8.4 reports them escaped
     * with a charset introducer (`kind` in (_utf8mb4\'call\',…)).
     *
     * @return list<string>
     */
    private function literals(string $clause): array
    {
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", str_replace("\\'", "'", $clause), $matches);

        return $matches[1];
    }

    private function leadsAnIndex(string $table, string $column): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->where('SEQ_IN_INDEX', 1)
            ->exists();
    }

    /**
     * @return Collection<int, object{table: string, column: string, referenced: string, rule: string}>
     */
    private function foreignKeys(): Collection
    {
        /** @var Collection<int, object{table: string, column: string, referenced: string, rule: string}> $keys */
        $keys = DB::table('information_schema.REFERENTIAL_CONSTRAINTS as r')
            ->join('information_schema.KEY_COLUMN_USAGE as k', function ($join): void {
                $join->on('k.CONSTRAINT_NAME', '=', 'r.CONSTRAINT_NAME')->on('k.CONSTRAINT_SCHEMA', '=', 'r.CONSTRAINT_SCHEMA');
            })
            ->where('r.CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->get(['k.TABLE_NAME as table', 'k.COLUMN_NAME as column', 'k.REFERENCED_TABLE_NAME as referenced', 'r.DELETE_RULE as rule'])
            ->map(static fn (object $key): object => (object) [
                'table' => (string) $key->table,
                'column' => (string) $key->column,
                'referenced' => (string) $key->referenced,
                'rule' => (string) $key->rule,
            ]);

        return $keys;
    }
}
