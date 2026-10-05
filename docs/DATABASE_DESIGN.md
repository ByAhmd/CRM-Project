# CRM — Database Design (as built)

Status: **as built at the end of step 12, 2026-09-15.** Designed 2026-09-05 (decisions D-1 … D-13), amended by the
quality-pass migrations dated 2026-09-14 (section 6) and checked against `information_schema` on `crm_testing_c` after
`migrate:fresh` on MySQL 8.4.11. The migrations are also proven on MariaDB 10.4.32 (A-21); engine differences are listed
under *Engine-aware migrations* below. The schema contract is pinned by `tests/Feature/Database/SchemaContractTest.php`
and the `tests/Feature/QualityPass/Schema` probes. Later owner decisions amend it in place: task updates and comments
(D-14 amendment, D-17), and the migrations dated 2026-10-04 — `task_update_mentions` and the `task_mention` preference
event (D-20), `tasks.assigned_at` (D-21), `task_templates`, `task_template_user` and `tasks.template_id` (D-22) —
and the migrations dated 2026-10-05: `task_checklist_items` and `task_template_checklist_items` (D-25), plus the data
migration `2026_10_05_100001_grant_workspace_permissions_to_existing_roles` (D-23), which changes no table: it adds the
four `workspace.*` rows to `permissions` and grants them to the existing roles (docs/PERMISSIONS.md, *Workspaces*).

## Conventions

- MySQL 8.4 (also proven on MariaDB 10.4, A-21), InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`, snake_case plural table names.
- `id` `BIGINT UNSIGNED` auto-increment PK on every table except keyless pivots.
- `created_at` / `updated_at` on every table except append-only logs (`created_at` only) and keyless pivots.
- `deleted_at` (soft delete) on business entities only — never on logs, pivots, lookups' values or sessions.
- Foreign keys are explicit. Default `ON DELETE RESTRICT`. `CASCADE` only for rows that cannot exist
  without their parent (pivots, custom-field values, stage logs of a hard-deleted deal). `SET NULL` for
  optional references to users (`owner_id`, `created_by`) so a user can be removed without losing data.
- Code-enum columns: `VARCHAR(32)` + `CHECK` (through `App\Support\Database\EnumCheck`).
- Money `DECIMAL(14,2)`; probability `TINYINT UNSIGNED` (0–100); durations `SMALLINT UNSIGNED` minutes.
- Bilingual lookups: `name_ar VARCHAR(100) NOT NULL`, `name_en VARCHAR(100) NOT NULL`.
- Normalised contact columns: `email_normalized` (lower-cased, trimmed), `phone_normalized` (E.164 digits, VARCHAR(32) since migrations 2026_09_14_200001–200003: sized for a 30-character phone normalised to digits)
  maintained by observers; used by duplicate detection and global search.
- Index naming: `{table}_{cols}_{index|unique}`; every FK column indexed; composite indexes listed per table.
- One table per migration file; hand-numbered timestamps `2026_09_XX_NNNNNN`; docblock names the purpose
  and the decision it implements.

### Engine-aware migrations (D-1, A-21)

The production engine is confirmed in writing before the first production migration, so every statement the two engines
spell differently branches on `App\Support\Database\DatabaseEngine::isMariaDb()` (Laravel's `MySqlConnection::isMaria()`;
works with both the `mysql` and `mariadb` drivers). The full round trip — `migrate:fresh --seed`, `migrate:rollback --step=500`,
`migrate`, `db:seed` twice, `app:preflight` — exits 0 on MySQL 8.4.11 and MariaDB 10.4.32.

| Topic | MySQL 8.4 | MariaDB 10.4 |
|---|---|---|
| Nullability on a generated column (`lead_scoring_rules` key columns) | `NOT NULL` declared | refused (error 1064), so declared without it; `information_schema` shows `IS_NULLABLE = YES`, but `COALESCE` stores no NULL and the unique key refuses the same duplicates |
| FK on a STORED generated column | accepted, enforced | accepted, enforced; `SHOW CREATE TABLE` omits the default `ON DELETE RESTRICT`, `REFERENTIAL_CONSTRAINTS` reports RESTRICT |
| Dropping a CHECK (`EnumCheck`) | `DROP CHECK` | `DROP CONSTRAINT IF EXISTS` |
| Implicit TIMESTAMP default | `explicit_defaults_for_timestamp` on | off on 10.4: `lead_status_logs.changed_at` and `deal_stage_logs.changed_at` carry `DEFAULT current_timestamp() ON UPDATE current_timestamp()` between their create migration and `400008` / `400009` (and again if those are rolled back); after a full migrate no column has `ON UPDATE` |
| `json` columns | native JSON | `LONGTEXT` with a `json_valid()` CHECK (nine across the app tables) |

Only 10.4 was exercised; recheck on the host's exact version.

### DATETIME rollback refusal

Migrations `2026_09_14_400006` … `400010` widen workflow moments from TIMESTAMP to DATETIME (leads `scored_at`, `qualified_at`,
`converted_at`, `last_activity_at`, `stale_notified_at`; deals `won_at`, `lost_at`, `last_activity_at`; `lead_status_logs.changed_at`;
`deal_stage_logs.changed_at`; `notes.edited_at`). Their `down()` first calls `App\Support\Database\TimestampRange::refuseValuesOutside()`,
which throws before any DDL when a value lies outside the TIMESTAMP range (1970-01-01 00:00:01 to 2038-01-19 03:14:07 UTC, read in the
session time zone through `FROM_UNIXTIME`), naming each `table.column` and the offending row ids. Pinned by
`tests/Feature/Database/TimestampRollbackGuardTest.php` on both engines.

Legend: **PK** primary key · **FK→** foreign key · **U** unique · **I** index · **N** nullable · **SD** soft deletes

---

## 1. Framework and platform tables

| Table | Notes |
|---|---|
| `users` | Laravel base + `phone` N, `locale` VARCHAR(5) N, `timezone` VARCHAR(64) N, `status` enum(`pending`,`active`,`disabled`) CHECK default `pending` (I `users_status_index`), `team_id` FK→teams N SET NULL (I), `last_login_at` N, MFA columns (`app_authentication_secret` text N, `app_authentication_recovery_codes` text N, `has_email_authentication` bool) (D-11), `password` N (set through invitation), `remember_token`, SD. `email` U. |
| `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | Laravel defaults |
| `notifications` | Laravel/Filament database notifications (`php artisan make:notifications-table`) |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | spatie/laravel-permission defaults; `teams` feature off (D-2) |
| `activity_log` | spatie/laravel-activitylog default + extra migration adding I `(subject_type, subject_id, created_at)`, I `(causer_type, causer_id, created_at)`, I `(log_name, created_at)`, I `event` |
| `imports`, `failed_import_rows`, `exports` | Filament actions migrations (`vendor:publish --tag=filament-actions-migrations`) |
| `settings` | `key` VARCHAR(100) U, `value` JSON N, `group` VARCHAR(50) I, timestamps. Typed access through `SettingsRepository`; audited. |
| `teams` | `name_ar`, `name_en`, `manager_user_id` FK→users N SET NULL, `is_active` bool, `sort` SMALLINT, timestamps, SD. U (`name_en`), U (`name_ar`). |
| `notification_preferences` | `user_id` FK→users CASCADE, `event` VARCHAR(64) (CHECK from `NotificationEvent`, rebuilt through `EnumCheck` whenever a case is added — last by `2026_10_04_100002` for `task_mention`, D-20, whose `down()` deletes that event's rows and restores the earlier list), `database` bool default 1, `mail` bool default 0 (a missing row means the event's default: mail on for `record_assigned`, `task_mention`, `weekly_summary`, `backup_failed`), timestamps. U (`user_id`,`event`). |

**Removed table.** `saved_views` (`user_id` FK→users CASCADE, `resource`, `name`, `filters` JSON, `sort_column`,
`sort_direction`, `search`, `columns` JSON, `is_shared`, `is_default`, timestamps; U (`user_id`,`resource`,`name`)) was
dropped on 2026-09-17 by `2026_09_17_100001_drop_saved_views_table`: the owner found the saved-views buttons on the list
pages of no use, so the feature was withdrawn (A-8/A-18 amended). The rows were personal preferences, never business
records, so they were dropped rather than archived. The creating migration `2026_09_06_160001` stays in place for
databases mid-history, and the drop migration's `down()` recreates the table exactly as it was.

## 2. Lookups (configurable, bilingual)

| Table | Columns |
|---|---|
| `lead_sources` | `name_ar`, `name_en`, `is_active` bool, `sort` SMALLINT, timestamps. U (`name_en`), U (`name_ar`), I (`is_active`, `sort`). |
| `lead_statuses` | `name_ar`, `name_en`, `kind` enum(`new`,`working`,`qualified`,`unqualified`,`converted`) CHECK (I), `color` VARCHAR(20), `is_default` bool, `is_active` bool, `sort`, timestamps. U names. Exactly one `is_default`; exactly one `converted` kind row (LeadStatusSeeder + LeadStatusService; pages call the service from handleRecordCreation/handleRecordUpdate and DeleteAction->using). |
| `industries` | `name_ar`, `name_en`, `is_active`, `sort`, timestamps. U names. |
| `pipelines` | `name_ar`, `name_en`, `is_default` bool, `is_active` bool, `sort` SMALLINT UNSIGNED, timestamps, SD. U (`name_en`), U (`name_ar`); I (`is_default`); I (`is_active`,`sort`). Exactly one `is_default`, which cannot be deactivated or deleted (PipelineService). |
| `pipeline_stages` | `pipeline_id` FK→pipelines RESTRICT (I), `name_ar`, `name_en`, `kind` enum(`open`,`won`,`lost`) CHECK, `probability` TINYINT UNSIGNED CHECK 0–100 (`pipeline_stages_probability_check`), `color` VARCHAR(20) CHECK (BadgeColor) default `primary`, `is_default` bool, `sort` SMALLINT UNSIGNED, timestamps (no SD). U (`pipeline_id`,`name_en`), U (`pipeline_id`,`name_ar`); I (`pipeline_id`,`sort`); I (`pipeline_id`,`kind`). `pipeline_id` immutable (observer). Each pipeline has ≥1 open, exactly 1 won (probability 100), exactly 1 lost (probability 0) and exactly 1 default (open) stage (validated in PipelineService). |
| `activity_types` | `name_ar`, `name_en`, `kind` enum(`call`,`meeting`,`email`,`note`,`task`,`system`,`other`) CHECK (I), `icon` VARCHAR(50) N (Heroicon case name), `color`, `is_system` bool, `is_active`, `sort`, timestamps. U names; I (`is_active`,`sort`). System rows (one per kind) cannot be deleted nor change kind. |
| `deal_close_reasons` | `kind` enum(`won`,`lost`) CHECK (I), `name_ar`, `name_en`, `is_active`, `sort`, timestamps. U (`kind`,`name_en`), U (`kind`,`name_ar`). |
| `competitors` | `name` VARCHAR(150) U, `website` N, `notes` TEXT N, `is_active`, timestamps, SD. |
| `tags` | `name_ar`, `name_en`, `color`, `is_active`, timestamps. U names. |
| `lead_scoring_rules` (D-7) | `kind` enum(`source`,`status`,`field_filled`,`activity_recency`) CHECK (I), `reference_id` BIGINT UNSIGNED N (source/status id), `field` VARCHAR(50) N (for `field_filled`), `within_days` SMALLINT UNSIGNED N (for `activity_recency`), `points` SMALLINT, `is_active`, `sort` SMALLINT UNSIGNED, timestamps. I (`is_active`,`sort`). The observer nulls the columns a kind does not use. **STORED generated columns** (migration `2026_09_14_400011`, never written by the application): `reference_key` BIGINT UNSIGNED = `COALESCE(reference_id, 0)`, `field_key` VARCHAR(50) = `COALESCE(field, '')`, `within_days_key` SMALLINT UNSIGNED = `COALESCE(within_days, 0)` — NOT NULL on MySQL, declared without a nullability attribute on MariaDB (see *Engine-aware migrations*); `lead_source_id` BIGINT UNSIGNED N = `IF(kind = 'source', reference_id, NULL)` FK→lead_sources RESTRICT (I `lead_scoring_rules_lead_source_id_index`); `lead_status_id` BIGINT UNSIGNED N = `IF(kind = 'status', reference_id, NULL)` FK→lead_statuses RESTRICT (I `lead_scoring_rules_lead_status_id_index`). U `lead_scoring_rules_rule_unique` (`kind`,`reference_key`,`field_key`,`within_days_key`) — effective although unused columns are NULL. A lead source or status a rule uses cannot be deleted. |
| `email_templates` (D-10) | `name_ar`, `name_en`, `subject_ar`, `subject_en`, `body_ar` TEXT, `body_en` TEXT (merge tags `{{contact.first_name}}` …), `entity` VARCHAR(32) N CHECK (lead/contact/account/deal; the UI offers lead and contact) (I), `is_active`, `sort`, timestamps, SD. U names; I (`is_active`,`sort`). |
| `taggables` | `tag_id` FK→tags CASCADE, `taggable_type` VARCHAR(100), `taggable_id` BIGINT. PK (`tag_id`,`taggable_type`,`taggable_id`); I (`taggable_type`,`taggable_id`). Keyless. |
| `products` (D-8) | `code` VARCHAR(50) U N (normalised to trimmed uppercase on save; blank → NULL), `name_ar`, `name_en`, `unit_price` DECIMAL(14,2), `is_active`, timestamps, SD. |
| `custom_fields` (D-9) | `entity` VARCHAR(32) CHECK (lead/contact/account/deal) (I), `key` VARCHAR(50) (immutable after creation), `label_ar`, `label_en` VARCHAR(100), `type` enum(`text`,`textarea`,`number`,`decimal`,`date`,`datetime`,`boolean`,`select`,`multiselect`,`url`,`email`) CHECK, `options` JSON N (select options, bilingual), `is_required` bool, `is_filterable` bool, `is_listed` bool, `is_active`, `sort`, `validation` JSON N (`min`/`max`/`step` for numbers, `min_length`/`max_length`/`regex` for text; the pattern is compiled and bounded to 200 characters at definition time), timestamps. U (`entity`,`key`); I (`entity`,`is_active`,`sort`). |
| `custom_field_values` (D-9) | `custom_field_id` FK→custom_fields CASCADE, `entity_type` VARCHAR(100), `entity_id` BIGINT, typed columns `value_string` VARCHAR(500) N, `value_text` TEXT N (bounded to 16 383 characters so four-byte UTF-8 always fits the 65 535-byte column), `value_integer` BIGINT N, `value_decimal` DECIMAL(18,4) N, `value_date` DATE N, `value_datetime` DATETIME N, `value_boolean` bool N, `value_json` JSON N (multiselect only), timestamps. U (`custom_field_id`,`entity_type`,`entity_id`); I (`entity_type`,`entity_id`); I (`custom_field_id`,`value_string`); I (`custom_field_id`,`value_integer`); I (`custom_field_id`,`value_date`). |

## 3. Core entities

### `accounts` (companies and customers are one entity — D-6)

| Column | Type | Notes |
|---|---|---|
| `name` | VARCHAR(150) | I; not unique (duplicate warning instead) |
| `type` | enum(`prospect`,`customer`,`partner`,`other`) CHECK | I; a prospect becomes `customer` on its first won deal (D-6) |
| `industry_id` | FK→industries RESTRICT N | I |
| `size` | enum(`1_10`,`11_50`,`51_200`,`201_500`,`501_1000`,`1000_plus`) CHECK N | |
| `website`, `email`, `phone` | VARCHAR N | `email_normalized` I, `phone_normalized` I |
| `address_line`, `city`, `region`, `country` (ISO-2), `postal_code` | VARCHAR N | `country` I |
| `owner_id` | FK→users N SET NULL | I |
| `parent_account_id` | FK→accounts N SET NULL | I |
| `customer_since` | DATE N | set when `type` becomes `customer` |
| `description` | TEXT N | |
| `created_by` | FK→users N SET NULL | |
| timestamps, SD | | |

### `contacts`

| Column | Type | Notes |
|---|---|---|
| `account_id` | FK→accounts N RESTRICT | I |
| `first_name`, `last_name` | VARCHAR(80) | I (`last_name`,`first_name`) |
| `job_title`, `department` | VARCHAR(100) N | |
| `email` | VARCHAR(190) N | `email_normalized` I |
| `phone`, `mobile` | VARCHAR(30) N | `phone_normalized` I |
| `preferred_locale` | VARCHAR(5) N | `ar`/`en` |
| `linkedin_url` | VARCHAR(255) N | |
| `address_line`, `city`, `region`, `country`, `postal_code` | N | |
| `is_primary` | bool | one primary per account (service-enforced) |
| `owner_id` | FK→users N SET NULL | I |
| `lead_id` | FK→leads N SET NULL | origin lead |
| `description` | TEXT N | |
| `created_by` | FK→users N SET NULL | |
| timestamps, SD | | |

### `leads`

| Column | Type | Notes |
|---|---|---|
| `first_name`, `last_name` | VARCHAR(80) | |
| `company_name` | VARCHAR(150) N | I |
| `job_title` | VARCHAR(100) N | |
| `email` | VARCHAR(190) N | `email_normalized` I |
| `phone` | VARCHAR(30) N | `phone_normalized` I |
| `website` | VARCHAR(255) N | |
| `address_line`, `city`, `region`, `country`, `postal_code` | N | |
| `lead_source_id` | FK→lead_sources RESTRICT N | I |
| `lead_status_id` | FK→lead_statuses RESTRICT | I; workflow-guarded |
| `owner_id` | FK→users N SET NULL | I; composite I (`owner_id`,`lead_status_id`) |
| `priority` | enum(`low`,`medium`,`high`) CHECK | I |
| `score` | SMALLINT UNSIGNED | default 0; computed by `LeadScoringService` from `lead_scoring_rules` (D-7) |
| `score_override` | SMALLINT UNSIGNED N | manual override; effective score = override ?? score (D-7) |
| `scored_at` | DATETIME N | last recalculation |
| `qualified_at` | DATETIME N | set by workflow |
| `qualified_by` | FK→users N SET NULL | |
| `converted_at` | DATETIME N | I |
| `converted_by` | FK→users N SET NULL | |
| `converted_account_id` | FK→accounts N SET NULL | |
| `converted_contact_id` | FK→contacts N SET NULL | |
| `converted_deal_id` | FK→deals N SET NULL | added by the deals migration (step 5) |
| `last_activity_at` | DATETIME N | I; maintained by `ActivityRecorder` for stale detection |
| `stale_notified_at` | DATETIME N | I; stamped by `LeadStaleService` once the owner was told the lead is stale, cleared by `ActivityRecorder` when `last_activity_at` moves forward |
| `description` | TEXT N | |
| `created_by` | FK→users N SET NULL | |
| timestamps, SD | | |

### `lead_status_logs` (append-only)

`lead_id` FK→leads CASCADE (I), `from_status_id` FK→lead_statuses N RESTRICT, `to_status_id` FK→lead_statuses RESTRICT,
`changed_by` FK→users N SET NULL, `changed_at` DATETIME (I), `notes` TEXT N. No `updated_at`.

### `deals`

| Column | Type | Notes |
|---|---|---|
| `title` | VARCHAR(150) | I |
| `account_id` | FK→accounts RESTRICT N | I (nullable only for deals converted from a lead without company — service decides) |
| `contact_id` | FK→contacts N SET NULL | primary contact; others through `deal_contacts` |
| `pipeline_id` | FK→pipelines RESTRICT | I |
| `stage_id` | FK→pipeline_stages RESTRICT | I; workflow-guarded; composite I (`pipeline_id`,`stage_id`) |
| `owner_id` | FK→users N SET NULL | I; composite I (`owner_id`,`status`) |
| `status` | enum(`open`,`won`,`lost`) CHECK | I; derived from stage kind by the workflow |
| `amount` | DECIMAL(14,2) | computed from `deal_products` lines; manual when there are no lines (D-8) |
| `currency` | CHAR(3) | default `SAR` from settings (D-8) |
| `probability` | TINYINT UNSIGNED N | override; effective = override ?? stage probability; CHECK NULL or 0–100 (`deals_probability_check`) |
| `expected_close_date` | DATE N | I |
| `forecast_category` | enum(`pipeline`,`best_case`,`commit`,`omitted`) CHECK | I |
| `lead_source_id` | FK→lead_sources N RESTRICT | |
| `lead_id` | FK→leads N SET NULL | origin |
| `close_reason_id` | FK→deal_close_reasons N RESTRICT | required when `status` ≠ open (service) |
| `won_at`, `lost_at` | DATETIME N | I (`won_at`) |
| `lost_notes` | TEXT N | |
| `last_activity_at` | DATETIME N | I |
| `description` | TEXT N | |
| `created_by` | FK→users N SET NULL | |
| timestamps, SD | | |

### `deal_stage_logs` (append-only)

`deal_id` FK→deals CASCADE (I), `from_stage_id` FK→pipeline_stages N RESTRICT, `to_stage_id` FK→pipeline_stages RESTRICT,
`changed_by` FK→users N SET NULL, `changed_at` DATETIME (I), `notes` TEXT N, `duration_seconds` INT UNSIGNED N (time in previous stage).

### `deal_contacts`

`deal_id` FK→deals CASCADE, `contact_id` FK→contacts CASCADE, `role` enum(`decision_maker`,`influencer`,`champion`,`user`,`other`) CHECK N, timestamps. U (`deal_id`,`contact_id`).

### `deal_competitors`

`deal_id` FK→deals CASCADE, `competitor_id` FK→competitors RESTRICT, `is_winner` bool, `notes` TEXT N, timestamps. U (`deal_id`,`competitor_id`).

### `deal_products` (D-8)

`deal_id` FK→deals CASCADE (I), `product_id` FK→products RESTRICT (I), `description` VARCHAR(255) N, `quantity` DECIMAL(12,2), `unit_price` DECIMAL(14,2), `discount_percent` DECIMAL(5,2) CHECK 0–100 (`deal_products_discount_percent_check`), `line_total` DECIMAL(14,2), `sort` SMALLINT, timestamps.

## 4. Activity, tasks, notes, attachments

### `activities` (immutable events)

| Column | Type | Notes |
|---|---|---|
| `activity_type_id` | FK→activity_types RESTRICT | I |
| `kind` | enum(`call`,`meeting`,`email`,`note`,`task`,`system`,`other`) CHECK | I; copied from type or set by the system |
| `subject` | VARCHAR(200) | |
| `body` | TEXT N | |
| `direction` | enum(`inbound`,`outbound`) CHECK N | calls/emails |
| `occurred_at` | DATETIME | I |
| `duration_minutes` | SMALLINT UNSIGNED N | |
| `outcome` | VARCHAR(100) N | |
| `lead_id` / `contact_id` / `account_id` / `deal_id` | FK N SET NULL | each I; at least one required (service) |
| `task_id` | FK→tasks N SET NULL | system entry written on completion |
| `note_id` | FK→notes N SET NULL | |
| `owner_id` | FK→users N SET NULL | I |
| `created_by` | FK→users N SET NULL | |
| `payload` | JSON N | system entries (e.g. conversion ids) |
| `created_at` | | no `updated_at`; append-only observer |

### `tasks`

| Column | Type | Notes |
|---|---|---|
| `title` | VARCHAR(200) | |
| `description` | TEXT N | |
| `kind` | enum(`task`,`follow_up`,`call`,`meeting`) CHECK | I |
| `status` | enum(`pending`,`in_progress`,`completed`,`cancelled`) CHECK | I; composite I (`assignee_id`,`status`,`due_at`) |
| `priority` | enum(`low`,`medium`,`high`,`urgent`) CHECK | I |
| `due_at` | DATETIME N | I |
| `starts_at`, `ends_at` | DATETIME N | calendar span for meetings |
| `completed_at` | DATETIME N | |
| `reminder_at` | DATETIME N | I |
| `reminder_sent_at` | DATETIME N | idempotency |
| `overdue_notified_at` | DATETIME N | |
| `assignee_id` | FK→users N SET NULL | I |
| `assigned_by` | FK→users N SET NULL | I; who handed the task to its assignee (D-14), written by the services only |
| `assigned_at` | DATETIME N | I `tasks_assigned_at_index`; when the task reached its current assignee (D-21, migration `2026_10_04_200001`): written only by `TaskAssignmentStampObserver` — stamped on create with an assignee, restamped when the assignee changes, cleared when unassigned, a direct write undone; not fillable, not audited. Backfilled from `created_at` for the rows that had an assignee; `down()` drops index and column. No CHECK ties it to `assignee_id`: MySQL 8 refuses a CHECK on a column with an FK referential action (error 3823) |
| `lead_id` / `contact_id` / `account_id` / `deal_id` | FK N SET NULL | each I |
| `recurrence_frequency` | enum(`none`,`daily`,`weekly`,`monthly`) CHECK | default `none` |
| `recurrence_interval` | TINYINT UNSIGNED N | every N units |
| `recurrence_ends_at` | DATE N | |
| `series_id` | FK→tasks N SET NULL | first task of the series |
| `template_id` | FK→task_templates N SET NULL | I `tasks_template_id_index`; the routine the task was created from (D-22, migration `2026_10_04_300003`), written by `RoutineTaskService` only (not fillable); cleared when the routine is deleted, the task stays |
| `created_by` | FK→users N SET NULL | |
| timestamps, SD | | |

### `task_updates` (append-only task thread: progress entries and comments — D-14 amendment, 2026-09-28; D-17)

`task_id` FK→tasks CASCADE (owned child; tasks are only soft-deleted, so the thread survives a delete and a restore),
`user_id` FK→users N SET NULL (I; the author), `kind` enum(`progress`,`comment`) CHECK default `progress` (D-17,
migration `2026_09_30_100002`; `progress` for a start, a progress update, a completion or a reopening, `comment` for a
comment from anyone who may view the task), `status` enum(`pending`,`in_progress`,`completed`,`cancelled`) CHECK N (I;
the status the entry moved the task to — `in_progress` for a start, `completed` for a completion, `pending` for a reopening,
NULL for a plain progress note and for every comment), `body` TEXT N (plain text; start notes, updates and comments capped
at 2000 characters by `TaskService`, a comment is required), `created_at` DATETIME. I (`task_id`,`created_at`) for the
thread, I (`task_id`,`kind`) for a comment's earlier commenters. No `updated_at`; written by `TaskService` only,
`TaskUpdateAppendOnlyObserver` refuses every update and delete.

### `task_update_mentions` (append-only — D-20)

Who a task comment mentions (migration `2026_10_04_100001`): `task_update_id` FK→task_updates CASCADE, `task_id`
FK→tasks CASCADE (denormalised from the comment, so "which tasks was this user mentioned on" needs no join), `user_id`
FK→users CASCADE (a row exists only for its mentioned user; users are soft-deleted, so in practice a mention outlives the
account like the comment holding it), `created_at` DATETIME. U (`task_update_id`,`user_id`) — a comment mentions a user
once; I (`user_id`,`task_id`) answers the participant read path (`TaskPolicy::view`, `TaskResource::constrainToReadable`)
from the index; I (`task_id`) lists the earlier-mentioned recipients of a later comment. Written by
`TaskService::comment()` inside the comment's transaction; `TaskUpdateMentionAppendOnlyObserver` refuses every update
and delete and, on create, refuses a row whose entry is missing, is not a comment, or belongs to another task — the
denormalised `task_id` is what grants access, so it must be the comment's own. The comment text itself stays plain
(`@Name`); no name is stored here, so after a rename older comments read the old name and are no longer highlighted.

### `task_templates` (routine tasks — D-22)

Configuration, not a business entity: no soft deletes; deleting a routine never touches the tasks it created
(`tasks.template_id` SET NULL). Migration `2026_10_04_300001`.

| Column | Type | Notes |
|---|---|---|
| `title` | VARCHAR(200) | sized like `tasks.title`, which every task copies |
| `description` | TEXT N | at most 2000 characters (service) |
| `priority` | enum(`low`,`medium`,`high`,`urgent`) CHECK | default `medium` (`TaskPriority`) |
| `frequency` | enum(`weekly`,`monthly`) CHECK | I (`RoutineFrequency`) |
| `weekday` | TINYINT UNSIGNED N | 0 = Sunday … 6 = Saturday; CHECK `task_templates_weekday_check` (NULL or 0–6) |
| `day_of_month` | TINYINT UNSIGNED N | 1–28, so every month has it; CHECK `task_templates_day_of_month_check` (NULL or 1–28) |
| `due_time` | TIME | default 17:00:00; the time of day the tasks are due, organisation time |
| `due_in_days` | TINYINT UNSIGNED | default 0 (the scheduled day itself); CHECK `task_templates_due_in_days_check` (0–30) |
| `is_active` | bool | default 1; a paused routine produces nothing |
| `next_run_on` | DATE N | the organisation day the hourly `tasks:generate-routines` produces next; CHECK `task_templates_next_run_on_check`: set exactly while the routine is active |
| `last_run_on` | DATE N | the day produced last, so no day is produced twice (not even after a same-day schedule change or resume) |
| `assigned_by` | FK→users N SET NULL | I; whoever last saved the routine — its tasks are handed out in their name (D-14) |
| timestamps | | `updated_at` is the last save by a person; the hourly advance writes without timestamps |

CHECK `task_templates_schedule_check`: `weekly` ⇒ `weekday` set and `day_of_month` NULL; `monthly` ⇒ `day_of_month` set
and `weekday` NULL. I (`is_active`,`next_run_on`) serves the hourly pass. `assigned_by`, `next_run_on` and `last_run_on`
are not fillable; `RoutineTaskService` writes them. `down()` drops the enum CHECKs, then the table (the range and schedule
CHECKs go with it). Audited as configuration (`AuditsAsLookup`, explicit whitelist without the run days or the
assigner); assignee changes and a takeover by another saver (`assigner`, `previous_assigner`) are recorded by the
service as one `settings.lookup_updated` entry.

### `task_template_user` (routine assignees — D-22)

`task_template_id` FK→task_templates CASCADE, `user_id` FK→users CASCADE (I). U (`task_template_id`,`user_id`). No
timestamps. Written by `RoutineTaskService` (sync), one task per row on each scheduled day. Migration
`2026_10_04_300002`.

### `task_checklist_items` (task checklists — D-25)

| Column | Type | Notes |
|---|---|---|
| `task_id` | FK→tasks CASCADE | owned child (tasks are only soft-deleted, so a checklist survives a delete and a restore); leads I `task_checklist_items_task_id_position_index` (`task_id`,`position`) |
| `body` | VARCHAR(255) | one line, trimmed, inner whitespace collapsed |
| `position` | SMALLINT UNSIGNED | default 0; the order, 0-based |
| `completed_at` | DATETIME N | when the item was ticked; NULL = not done |
| `completed_by` | FK→users N SET NULL | I `task_checklist_items_completed_by_index`; who ticked it |
| `created_at`/`updated_at` | TIMESTAMP N | |

At most 50 rows per task. Written by `TaskService` only (through `TaskChecklist`): the structure (add, rename,
reorder, remove) by an edit that submits `checklist` — the task's row locked, only that task's rows touched, kept rows
keep their ticks — and the ticks by `tickChecklist` on an open task; `completed_at`/`completed_by` are workflow-guarded
on the model. Not `LogsActivity`: changes are recorded on the task as `task.checklist_changed` / `task.checklist_ticked`.
Migration `2026_10_05_500001`.

### `task_template_checklist_items` (routine checklists — D-25)

`task_template_id` FK→task_templates CASCADE (leads I `task_template_checklist_items_template_position_index` with
`position`), `body` VARCHAR(255), `position` SMALLINT UNSIGNED, timestamps. No ticks: copied, unticked and in order,
into every task the routine creates. Replaced by `RoutineTaskService` on a save that submits `checklist` and recorded
on the routine (`settings.lookup_updated`: `checklist_added`, `checklist_removed`, `checklist_reordered`). Migration
`2026_10_05_500002`.

### `notes`

`body` TEXT (rich JSON if RichEditor), `author_id` FK→users N SET NULL (I), `lead_id`/`contact_id`/`account_id`/`deal_id` FK N SET NULL (I each; ≥1 required),
`is_pinned` bool, `edited_at` DATETIME N, timestamps, SD. Edit history: `LogsActivity` `logOnly(['body'])`.

### `attachments`

| Column | Type | Notes |
|---|---|---|
| `uuid` | CHAR(36) U | download route key |
| `attachable_type`, `attachable_id` | morph | I (`attachable_type`,`attachable_id`) — lead/contact/account/deal/activity/task/note |
| `disk` | VARCHAR(30) | `private` |
| `path` | VARCHAR(255) | `crm/{entity}/{id}/{uuid}.{ext}` |
| `original_name` | VARCHAR(255) | |
| `mime_type` | VARCHAR(100) | server-sniffed |
| `size` | INT UNSIGNED | bytes |
| `description` | VARCHAR(255) N | |
| `uploaded_by` | FK→users N SET NULL | I |
| timestamps, SD | | file removed by observer on force delete |

## 5. Relationship summary

- User `hasMany` leads/contacts/accounts/deals/tasks (as owner/assignee), `belongsTo` team, `hasMany` notificationPreferences.
- Team `hasMany` users, `belongsTo` manager.
- Account `hasMany` contacts, deals, activities, tasks, notes, attachments (morph), `belongsToMany` tags; `belongsTo` industry, owner, parent.
- Contact `belongsTo` account, owner; `belongsToMany` deals (`deal_contacts` with role), tags; `hasMany` activities, tasks, notes; morph attachments.
- Lead `belongsTo` source, status, owner; `hasMany` statusLogs, activities, tasks, notes; morph attachments; `belongsToMany` tags; `belongsTo` convertedAccount/Contact/Deal.
- Deal `belongsTo` account, contact, pipeline, stage, owner, closeReason, lead; `hasMany` stageLogs, activities, tasks, notes, products; `belongsToMany` contacts, competitors, tags; morph attachments.
- Pipeline `hasMany` stages, deals.
- Activity/Task/Note `belongsTo` lead/contact/account/deal (nullable), owner/assignee/author; morph attachments.
- Task `hasMany` updates (`task_updates`, newest first), `belongsTo` assigner (`assigned_by`), `belongsTo` template (`template_id`, D-22); TaskUpdate `belongsTo` task, author, `hasMany` mentions (`task_update_mentions`, D-20).
- TaskTemplate `belongsToMany` assignees (`task_template_user`), `belongsTo` assigner (`assigned_by`, soft-deleted users included), `hasMany` tasks, `hasMany` checklistItems (`task_template_checklist_items`, in order, D-25).
- Task `hasMany` checklistItems (`task_checklist_items`, in order, D-25); TaskChecklistItem `belongsTo` task, completer (`completed_by`, soft-deleted users included).
- Custom field values `morphTo` entity, `belongsTo` field.

## 6. Data integrity rules enforced outside the schema (services + observers + tests)

Quality-pass amendments (migrations dated 2026-09-14): `leads`, `contacts` and `accounts` `phone_normalized` widened to VARCHAR(32); indexes added on `leads.created_at`, `leads.qualified_at`, `deals.created_at`, `deals.lost_at`, `tasks.starts_at` and `lead_status_logs.changed_at` for the dashboard, reports and timeline; `activity_types.color` carries the `BadgeColor` CHECK; the lead, deal, status-log, stage-log and note timestamps that hold business moments (`qualified_at`, `converted_at`, `won_at`, `lost_at`, `last_activity_at`, `changed_at`, `edited_at`) are DATETIME rather than TIMESTAMP so they never shift with the session timezone or overflow in 2038; `lead_scoring_rules` uniqueness is made effective through STORED generated key columns because MySQL treats NULLs in a composite unique index as distinct, and its `reference_id` gains per-kind generated `lead_source_id` / `lead_status_id` columns with RESTRICT foreign keys; the DATETIME conversions refuse to roll back while a value is outside the TIMESTAMP range (see *DATETIME rollback refusal*). The unique indexes on `teams.name_ar/name_en`, `pipelines.name_ar/name_en` and `users.email` are global: the forms refuse a soft-deleted namesake with a translated "restore instead" message rather than failing on insert.

Step-13 amendments (migrations dated 2026-09-15, from the EXPLAIN evidence on volume): `tasks_ends_at_index` (the calendar's slice of timed tasks that started before the range and run into it), `leads_deleted_at_lead_status_id_index` (the pagination counts of the lead list and its status tabs, answered from the index) and `deals_status_deleted_at_created_at_index` (the deal status tabs: page read in index order, count from the index). Plain secondary indexes, identical on MySQL 8 and MariaDB.


- Lead status / deal stage changes only through workflows (observer rejects direct writes).
- Exactly one default lead status, one default pipeline, one default stage per pipeline, one won and one lost stage per pipeline.
- `deals.status` always matches `stage.kind`; `close_reason_id` required on won/lost; `won_at`/`lost_at` set by the workflow only. A deal is created only in an Open stage of its own pipeline (`DealObserver`); closed deals change only through `reopen()`.
- Line items recalculate `deals.amount` through a normal `save()` so the amount change is audited as `deal.updated` (D-8); a deal without lines keeps its manual amount.
- Lead conversion is one transaction: create/link account, create/link contact, optionally create deal, set `converted_*`, move status to the `converted` kind, write status log, audit and timeline entries.
- Activities, tasks, notes must reference at least one of lead/contact/account/deal.
- Attachments: MIME allowlist (`pdf, doc, docx, xls, xlsx, csv, txt, png, jpg, jpeg, webp`) checked on the server-sniffed type, max size from `config('crm.attachments.max_kb')`, files parked under `tmp/{user id}/` until the form is submitted (pruned daily), soft delete keeps the file, force delete removes it, downloads go through the panel's authentication middleware and the subject's `view` policy; uploads are refused on soft-deleted subjects.
- Tasks: `status`, `completed_at`, `reminder_sent_at`, `overdue_notified_at` are written only by `TaskService` / `TaskReminderService`; editing `reminder_at` or moving `due_at` later re-arms the corresponding stamp; completing a recurring task creates the next occurrence in the same series until `recurrence_ends_at`; reassignment goes through `RecordAssignmentService`. Starting (Pending → In progress), posting a progress update, completing and reopening each append a `task_updates` row inside the same transaction (D-14 amendment, 2026-09-28); a comment appends a `kind = comment` row (no status) on an open or closed task, never on a soft-deleted one (D-17), plus one `task_update_mentions` row per mentionable colleague it mentions (active `task.view_any` holders other than the author; other ids are dropped) in the same transaction (D-20). `assigned_at` follows `assignee_id` on every Eloquent save (`TaskAssignmentStampObserver`, D-21); no code path changes `assignee_id` through the query builder or `saveQuietly`, and users are only soft-deleted, so the observer sees every change.
- Routine tasks (D-22): `RoutineTaskService` is the only writer of `task_templates` and `task_template_user`; every save checks the assignees against the saver's assignment reach; the hourly pass locks each due routine, re-checks it, creates one task per assignee who may sign in and holds `task.view_any` (with `assigned_by` and `template_id` force-filled) for the latest matching day only, and advances `next_run_on` / `last_run_on` in the same transaction. Each task gets the routine's checklist, unticked (D-25).
- Task checklists (D-25): written only through `TaskService`; an edit without the `checklist` key keeps the checklist, so every fill path of the task form (edit page, relation-manager modal, calendar modal) loads the stored items; a completed, cancelled or deleted task's checklist is frozen (structure changes refused, no ticks) and reopening keeps the ticks; completing with unticked items is allowed and the `task.completed` entry carries `checklist_open`/`checklist_total`; the next occurrence of a repeating task copies the checklist unticked; the list, board and export read the counts with `withCount` on their own queries (`TaskChecklist::withCounts`), never on `TaskResource::getEloquentQuery()`; a tick counts as progress for the weekly summary's stalled rule (D-18).
- Notes: body trimmed and capped at 5000 characters in the service; a note on a contact or deal is listed on the account only when the reader may read that contact or deal.
- Qualification: moving a lead into a status of kind `qualified` requires a non-empty note on the `lead_status_logs` row (D-7); conversion is refused unless the current status kind is `qualified` (D-7).
- Sending a templated email writes an outbound `email` activity with the rendered subject and body in `payload` (D-10).
- Normalised email/phone recomputed on save; duplicate warning surfaces on create/import when an exact normalised match exists in the same entity.
- The last active super admin cannot be demoted, disabled, set to pending or deleted; nobody may delete, disable or set back to pending their own account; only a `roles.manage` holder grants, removes or edits `super_admin` (`RoleService::syncUserRoles` / `assertStatusChange` / `isLastActiveSuperAdmin`, `UserPolicy`, A-12).
- Lookups referenced by rows cannot be deleted (RESTRICT) — they are deactivated instead.
