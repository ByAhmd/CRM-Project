<?php

declare(strict_types=1);

use App\Enums\LeadScoringRuleKind;
use App\Support\Database\DatabaseEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes the lead scoring rule keys hold at the database (decision D-7,
 * DATABASE_DESIGN.md lookups table `lead_scoring_rules`).
 *
 * 1. Uniqueness. The design lists U (kind, reference_id, field, within_days),
 *    but LeadScoringRuleObserver nulls the columns a kind does not use, so
 *    every rule carries at least two NULLs and MySQL treats NULLs as
 *    distinct: the index never refused a duplicate, and two identical
 *    "source = Website" rules scored a lead twice. Three STORED generated
 *    columns fold the NULLs into comparable values (reference_key,
 *    field_key, within_days_key) and the unique key moves onto them.
 *
 * 2. References. reference_id points at a lead source or a lead status
 *    depending on the kind, so it could not carry a foreign key and deleting
 *    the source or status left the rule pointing at nothing. Two STORED
 *    generated columns split it per kind (lead_source_id, lead_status_id)
 *    and each carries a RESTRICT foreign key, so a source or status a rule
 *    uses cannot be deleted — the settings policies hide the action first.
 *
 * The generated columns are derived, never written by the application: the
 * model keeps filling reference_id, field and within_days. Existing rows that
 * would break either key abort the migration before any DDL runs, naming the
 * rows, so no data is silently removed.
 *
 * Engine difference (D-1, App\Support\Database\DatabaseEngine). MySQL 8
 * declares the three key columns NOT NULL. MariaDB refuses a NULL / NOT NULL
 * attribute on a generated column (syntax error 1064), so there the key
 * columns are declared without it and show as nullable in information_schema;
 * COALESCE still makes every stored value non-NULL, so the unique key refuses
 * the same duplicates on both engines. Everything else is identical: MariaDB
 * accepts the STORED spelling, the unique and plain indexes, and the RESTRICT
 * foreign keys on the STORED generated columns, and enforces those keys on
 * insert, on update of reference_id or kind, and on delete of the source or
 * status (verified on MariaDB 10.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->refuseDuplicates();
        $this->refuseDanglingReferences();

        $pdo = DB::getPdo();
        $source = $pdo->quote(LeadScoringRuleKind::Source->value);
        $status = $pdo->quote(LeadScoringRuleKind::Status->value);
        $notNull = DatabaseEngine::isMariaDb() ? '' : ' NOT NULL';

        DB::statement('ALTER TABLE `lead_scoring_rules` DROP INDEX `lead_scoring_rules_rule_unique`');

        DB::statement(<<<SQL
            ALTER TABLE `lead_scoring_rules`
                ADD COLUMN `reference_key` BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(`reference_id`, 0)) STORED{$notNull} AFTER `within_days`,
                ADD COLUMN `field_key` VARCHAR(50) GENERATED ALWAYS AS (COALESCE(`field`, '')) STORED{$notNull} AFTER `reference_key`,
                ADD COLUMN `within_days_key` SMALLINT UNSIGNED GENERATED ALWAYS AS (COALESCE(`within_days`, 0)) STORED{$notNull} AFTER `field_key`,
                ADD COLUMN `lead_source_id` BIGINT UNSIGNED GENERATED ALWAYS AS (IF(`kind` = {$source}, `reference_id`, NULL)) STORED AFTER `within_days_key`,
                ADD COLUMN `lead_status_id` BIGINT UNSIGNED GENERATED ALWAYS AS (IF(`kind` = {$status}, `reference_id`, NULL)) STORED AFTER `lead_source_id`
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `lead_scoring_rules`
                ADD UNIQUE INDEX `lead_scoring_rules_rule_unique` (`kind`, `reference_key`, `field_key`, `within_days_key`),
                ADD INDEX `lead_scoring_rules_lead_source_id_index` (`lead_source_id`),
                ADD INDEX `lead_scoring_rules_lead_status_id_index` (`lead_status_id`),
                ADD CONSTRAINT `lead_scoring_rules_lead_source_id_foreign` FOREIGN KEY (`lead_source_id`) REFERENCES `lead_sources` (`id`) ON DELETE RESTRICT,
                ADD CONSTRAINT `lead_scoring_rules_lead_status_id_foreign` FOREIGN KEY (`lead_status_id`) REFERENCES `lead_statuses` (`id`) ON DELETE RESTRICT
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `lead_scoring_rules`
                DROP FOREIGN KEY `lead_scoring_rules_lead_source_id_foreign`,
                DROP FOREIGN KEY `lead_scoring_rules_lead_status_id_foreign`
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `lead_scoring_rules`
                DROP INDEX `lead_scoring_rules_rule_unique`,
                DROP INDEX `lead_scoring_rules_lead_source_id_index`,
                DROP INDEX `lead_scoring_rules_lead_status_id_index`,
                DROP COLUMN `lead_status_id`,
                DROP COLUMN `lead_source_id`,
                DROP COLUMN `within_days_key`,
                DROP COLUMN `field_key`,
                DROP COLUMN `reference_key`
            SQL);

        DB::statement('ALTER TABLE `lead_scoring_rules` ADD UNIQUE INDEX `lead_scoring_rules_rule_unique` (`kind`, `reference_id`, `field`, `within_days`)');
    }

    private function refuseDuplicates(): void
    {
        $duplicates = DB::table('lead_scoring_rules')
            ->selectRaw('GROUP_CONCAT(`id` ORDER BY `id`) AS ids')
            ->groupByRaw("`kind`, COALESCE(`reference_id`, 0), COALESCE(`field`, ''), COALESCE(`within_days`, 0)")
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ids')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException(sprintf(
                'lead_scoring_rules holds duplicate rules (ids %s). Delete or change all but one rule of each group in Settings > Lead scoring, then migrate again.',
                implode('; ', $duplicates),
            ));
        }
    }

    private function refuseDanglingReferences(): void
    {
        $dangling = DB::table('lead_scoring_rules')
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('kind', LeadScoringRuleKind::Source->value)
                    ->whereNotNull('reference_id')
                    ->whereNotIn('reference_id', DB::table('lead_sources')->select('id')))
                ->orWhere(fn ($query) => $query
                    ->where('kind', LeadScoringRuleKind::Status->value)
                    ->whereNotNull('reference_id')
                    ->whereNotIn('reference_id', DB::table('lead_statuses')->select('id'))))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($dangling !== []) {
            throw new RuntimeException(sprintf(
                'lead_scoring_rules rules %s reference a lead source or status that no longer exists. Delete them in Settings > Lead scoring, then migrate again.',
                implode(', ', $dangling),
            ));
        }
    }
};
