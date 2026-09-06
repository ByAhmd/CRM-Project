# CRM — Database Design (v1 proposal)

Status: approved design, 2026-09-05 (decisions D-1 … D-13 applied). Becomes `docs/DATABASE.md` (as-built) once migrations exist.

## Conventions

- MySQL 8.4, InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`, snake_case plural table names.
- `id` `BIGINT UNSIGNED` auto-increment PK on every table except keyless pivots.
- `created_at` / `updated_at` on every table except append-only logs (`created_at` only) and keyless pivots.
- `deleted_at` (soft delete) on business entities only — never on logs, pivots, lookups' values or sessions.
- Foreign keys are explicit. Default `ON DELETE RESTRICT`. `CASCADE` only for rows that cannot exist
  without their parent (pivots, custom-field values, stage logs of a hard-deleted deal). `SET NULL` for
  optional references to users (`owner_id`, `created_by`) so a user can be removed without losing data.
- Code-enum columns: `VARCHAR(32)` + `CHECK` (through `App\Support\Database\EnumCheck`).
- Money `DECIMAL(14,2)`; probability `TINYINT UNSIGNED` (0–100); durations `SMALLINT UNSIGNED` minutes.
- Bilingual lookups: `name_ar VARCHAR(100) NOT NULL`, `name_en VARCHAR(100) NOT NULL`.
- Normalised contact columns: `email_normalized` (lower-cased, trimmed), `phone_normalized` (E.164 digits)
  maintained by observers; used by duplicate detection and global search.
- Index naming: `{table}_{cols}_{index|unique}`; every FK column indexed; composite indexes listed per table.
- One table per migration file; hand-numbered timestamps `2026_09_XX_NNNNNN`; docblock names the purpose
  and the decision it implements.

Legend: **PK** primary key · **FK→** foreign key · **U** unique · **I** index · **N** nullable · **SD** soft deletes

---

## 1. Framework and platform tables

| Table | Notes |
|---|---|
| `users` | Laravel base + `phone` N, `locale` VARCHAR(5) N, `timezone` VARCHAR(64) N, `status` enum(`pending`,`active`,`disabled`) CHECK default `pending`, `team_id` FK→teams N SET NULL (I), `last_login_at` N, `avatar_path` N, MFA columns (`app_authentication_secret` text N, `app_authentication_recovery_codes` text N, `has_email_authentication` bool) (D-11), `password` N (set through invitation), `remember_token`, SD. `email` U. |
| `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | Laravel defaults |
| `notifications` | Laravel/Filament database notifications (`php artisan make:notifications-table`) |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | spatie/laravel-permission defaults; `teams` feature off (D-2) |
| `activity_log` | spatie/laravel-activitylog default + extra migration adding I `(subject_type, subject_id, created_at)`, I `(causer_type, causer_id, created_at)`, I `(log_name, created_at)`, I `event` |
| `imports`, `failed_import_rows`, `exports` | Filament actions migrations (`vendor:publish --tag=filament-actions-migrations`) |
| `settings` | `key` VARCHAR(100) U, `value` JSON N, `group` VARCHAR(50) I, timestamps. Typed access through `SettingsRepository`; audited. |
| `teams` | `name_ar`, `name_en`, `manager_user_id` FK→users N SET NULL, `is_active` bool, `sort` SMALLINT, timestamps, SD. U (`name_en`), U (`name_ar`). |
| `notification_preferences` | `user_id` FK→users CASCADE, `event` VARCHAR(64) (CHECK from `NotificationEvent`), `database` bool default 1, `mail` bool default 0, timestamps. U (`user_id`,`event`). |
| `saved_views` | `user_id` FK→users CASCADE, `resource` VARCHAR(100) I, `name` VARCHAR(100), `filters` JSON N, `sort_column` N, `sort_direction` VARCHAR(4) N, `search` N, `columns` JSON N, `is_shared` bool, `is_default` bool, timestamps. U (`user_id`,`resource`,`name`). |

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
| `lead_scoring_rules` (D-7) | `kind` enum(`source`,`status`,`field_filled`,`activity_recency`) CHECK (I), `reference_id` BIGINT N (source/status id), `field` VARCHAR(50) N (for `field_filled`), `within_days` SMALLINT N (for `activity_recency`), `points` SMALLINT, `is_active`, `sort`, timestamps. U (`kind`,`reference_id`,`field`,`within_days`). |
| `email_templates` (D-10) | `name_ar`, `name_en`, `subject_ar`, `subject_en`, `body_ar` TEXT, `body_en` TEXT (merge tags `{{contact.first_name}}` …), `entity` VARCHAR(50) N (lead/contact/account/deal), `is_active`, `sort`, timestamps, SD. U names. |
| `taggables` | `tag_id` FK→tags CASCADE, `taggable_type` VARCHAR(100), `taggable_id` BIGINT. PK (`tag_id`,`taggable_type`,`taggable_id`); I (`taggable_type`,`taggable_id`). Keyless. |
| `products` (D-8) | `code` VARCHAR(50) U N (normalised to trimmed uppercase on save; blank → NULL), `name_ar`, `name_en`, `unit_price` DECIMAL(14,2), `is_active`, timestamps, SD. |
| `custom_fields` (D-9) | `entity` VARCHAR(50) (CHECK: lead/contact/account/deal) (I), `key` VARCHAR(50), `label_ar`, `label_en`, `type` enum(`text`,`textarea`,`number`,`decimal`,`date`,`datetime`,`boolean`,`select`,`multiselect`,`url`,`email`) CHECK, `options` JSON N (select options, bilingual), `is_required` bool, `is_filterable` bool, `is_listed` bool, `is_active`, `sort`, `validation` JSON N, timestamps. U (`entity`,`key`). |
| `custom_field_values` (D-9) | `custom_field_id` FK→custom_fields CASCADE, `entity_type` VARCHAR(100), `entity_id` BIGINT, typed columns `value_string` VARCHAR(500) N, `value_text` TEXT N, `value_integer` BIGINT N, `value_decimal` DECIMAL(18,4) N, `value_date` DATE N, `value_datetime` DATETIME N, `value_boolean` bool N, `value_json` JSON N (multiselect only), timestamps. U (`custom_field_id`,`entity_type`,`entity_id`); I (`entity_type`,`entity_id`); I (`custom_field_id`,`value_string`); I (`custom_field_id`,`value_integer`); I (`custom_field_id`,`value_date`). |

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
| `lead_id` / `contact_id` / `account_id` / `deal_id` | FK N SET NULL | each I |
| `recurrence_frequency` | enum(`none`,`daily`,`weekly`,`monthly`) CHECK | default `none` |
| `recurrence_interval` | TINYINT UNSIGNED N | every N units |
| `recurrence_ends_at` | DATE N | |
| `series_id` | FK→tasks N SET NULL | first task of the series |
| `created_by` | FK→users N SET NULL | |
| timestamps, SD | | |

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

- User `hasMany` leads/contacts/accounts/deals/tasks (as owner/assignee), `belongsTo` team, `hasMany` savedViews, notificationPreferences.
- Team `hasMany` users, `belongsTo` manager.
- Account `hasMany` contacts, deals, activities, tasks, notes, attachments (morph), `belongsToMany` tags; `belongsTo` industry, owner, parent.
- Contact `belongsTo` account, owner; `belongsToMany` deals (`deal_contacts` with role), tags; `hasMany` activities, tasks, notes; morph attachments.
- Lead `belongsTo` source, status, owner; `hasMany` statusLogs, activities, tasks, notes; morph attachments; `belongsToMany` tags; `belongsTo` convertedAccount/Contact/Deal.
- Deal `belongsTo` account, contact, pipeline, stage, owner, closeReason, lead; `hasMany` stageLogs, activities, tasks, notes, products; `belongsToMany` contacts, competitors, tags; morph attachments.
- Pipeline `hasMany` stages, deals.
- Activity/Task/Note `belongsTo` lead/contact/account/deal (nullable), owner/assignee/author; morph attachments.
- Custom field values `morphTo` entity, `belongsTo` field.

## 6. Data integrity rules enforced outside the schema (services + observers + tests)

- Lead status / deal stage changes only through workflows (observer rejects direct writes).
- Exactly one default lead status, one default pipeline, one default stage per pipeline, one won and one lost stage per pipeline.
- `deals.status` always matches `stage.kind`; `close_reason_id` required on won/lost; `won_at`/`lost_at` set by the workflow only. A deal is created only in an Open stage of its own pipeline (`DealObserver`); closed deals change only through `reopen()`.
- Line items recalculate `deals.amount` through a normal `save()` so the amount change is audited as `deal.updated` (D-8); a deal without lines keeps its manual amount.
- Lead conversion is one transaction: create/link account, create/link contact, optionally create deal, set `converted_*`, move status to the `converted` kind, write status log, audit and timeline entries.
- Activities, tasks, notes must reference at least one of lead/contact/account/deal.
- Attachments: MIME allowlist (`pdf, doc, docx, xls, xlsx, csv, txt, png, jpg, jpeg, webp`) checked on the server-sniffed type, max size from `config('crm.attachments.max_kb')`, files parked under `tmp/{user id}/` until the form is submitted (pruned daily), soft delete keeps the file, force delete removes it, downloads go through the panel's authentication middleware and the subject's `view` policy; uploads are refused on soft-deleted subjects.
- Tasks: `status`, `completed_at`, `reminder_sent_at`, `overdue_notified_at` are written only by `TaskService` / `TaskReminderService`; editing `reminder_at` or moving `due_at` later re-arms the corresponding stamp; completing a recurring task creates the next occurrence in the same series until `recurrence_ends_at`; reassignment goes through `RecordAssignmentService`.
- Notes: body trimmed and capped at 5000 characters in the service; a note on a contact or deal is listed on the account only when the reader may read that contact or deal.
- Qualification: moving a lead into a status of kind `qualified` requires a non-empty note on the `lead_status_logs` row (D-7); conversion is refused unless the current status kind is `qualified` (D-7).
- Sending a templated email writes an outbound `email` activity with the rendered subject and body in `payload` (D-10).
- Normalised email/phone recomputed on save; duplicate warning surfaces on create/import when an exact normalised match exists in the same entity.
- Last super admin cannot be demoted or disabled.
- Lookups referenced by rows cannot be deleted (RESTRICT) — they are deactivated instead.
