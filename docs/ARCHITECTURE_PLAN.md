# CRM — Architecture and Implementation Plan

Status: **Plan approved 2026-09-05. All 13 owner questions answered — see [DECISIONS.md](DECISIONS.md) (D-1 … D-13, A-1 … A-21). Steps 0–6 (scaffold, foundation, lookups, accounts & contacts, leads, deals & pipelines, lead conversion) complete 2026-09-06; step 7 (activities, notes, attachments, tasks, timeline, calendar) and step 8 (notifications, scheduler, templated email) complete 2026-09-06; step 9 (search, query-builder filters, saved views — since removed as unused, see the A-8 amendment — and import/export), step 10 (dashboard and reports) and step 11 (custom fields) complete 2026-09-07; step 12 (quality pass) complete 2026-09-15 — open go-live items are carried in [GoLive_Checklist.md](GoLive_Checklist.md). Step 13 (production readiness) engineering complete 2026-09-15; go-live rows that need the host or the owner are tracked in [GoLive_Checklist.md](GoLive_Checklist.md).**

Companion documents:

| Document | Purpose |
|---|---|
| [DATABASE_DESIGN.md](DATABASE_DESIGN.md) | Every table, column, key, index and constraint, as built |
| [STOCKFLOW_COMPARISON.md](STOCKFLOW_COMPARISON.md) | Stockflow vs CRM comparison table and the reuse classification |
| [DECISIONS.md](DECISIONS.md) | Owner decisions D-1 … D-13 and architect decisions A-1 … A-21 |
| [PERMISSIONS.md](PERMISSIONS.md) | Seeded roles, record scope and the guards above the permissions |
| [GoLive_Checklist.md](GoLive_Checklist.md) | Step-12 exit checklist: every go-live item with its evidence, result and owner |
| [OPEN_DECISIONS.md](OPEN_DECISIONS.md) | The questions as asked on 2026-09-04 (historical record; all resolved) |

---

## 1. Discovery findings

### 1.1 The CRM project

`C:\Users\ahmed\Desktop\CRM_Project` was **empty** (zero files, not a git repository) on 2026-09-04.
There is no existing CRM architecture to preserve; the baseline is therefore the engineering
standard established by Stockflow, adapted to the CRM domain.

### 1.2 The reference project (Stockflow / ZonKSA)

Located and verified at `C:\Users\ahmed\Desktop\StockFlow-MainWork` (Laravel application at the
repository root, remote `FatimaMahbani/stockflow`, branch `develop-ahmed`, 136 commits). It is a
read-only reference and was not modified.

Verified stack: Laravel 13.23 · Filament 5.7.3 · Livewire 4.3.3 · PHP `^8.3` · MySQL 8.4 ·
spatie/laravel-permission 8.3 · bezhansalleh/filament-shield 4.3 · bezhansalleh/filament-language-switch 5.0 ·
spatie/laravel-activitylog 4.12 · PHPUnit 12 (attributes, no Pest) · Larastan 3.10 level 5 with
`checkModelProperties` · Pint default preset · Vite 8 + Tailwind 4 CSS-first theme.

Eleven architectural areas were read by independent agents, each map was adversarially verified
against the code (300+ claims checked, 12 refuted and corrected), and the corrected maps drive the
comparison in [STOCKFLOW_COMPARISON.md](STOCKFLOW_COMPARISON.md).

### 1.3 Local environment (verified on this machine)

| Component | Finding | Consequence for the CRM |
|---|---|---|
| PHP | Herd PHP **8.4.24** (`~/.config/herd/bin/php.bat`). In PowerShell `php` resolves to Herd. In Git Bash `php` resolves to XAMPP **8.2.12**, which cannot run Laravel 13. `C:\php83` (8.3.32) also exists. | All commands run through Herd's `composer`/`php`; README documents the Git Bash trap. Language level pinned to PHP 8.3 (production floor) with local 8.4. |
| Composer | Herd Composer 2.10.2; Laravel installer 5.31.1 | Scaffold with `composer create-project laravel/laravel` under Herd PHP |
| MySQL | Standalone MySQL **8.4.11** on 127.0.0.1:3306 (root, no password), `utf8mb4_unicode_ci`, strict `sql_mode` incl. `ONLY_FULL_GROUP_BY`, `lower_case_table_names=1`, `skip-log-bin`. Client at `~/.mysql/mysql-8.4.11-winx64/bin/mysql.exe` (not on PATH). XAMPP MariaDB installed but not running. | Create `crm` and `crm_testing` databases + `crm` user; tests run on real MySQL; never start XAMPP MariaDB on 3306 |
| Herd sites | Symlinks in `~/.config/herd/config/valet/Sites`, TLD `.test` | `herd link crm` → `http://crm.test` |
| Herd php.ini | `upload_max_filesize=2M`, `post_max_size=8M`, `memory_limit=128M` | Attachment/import limits documented; `memory_limit=1G` pinned in `phpunit.xml` and PHPStan |
| Node / npm | 24.19 / 11.17 (Stockflow CI uses Node 22) | Pin `engines` and CI to Node 22 |
| Git | 2.51, identity Ahmed Almusaed, no `gh` CLI, GitHub Desktop credentials | Push over HTTPS; remote `ByAhmd/CRM-Project` exists and is empty |
| Redis / Supervisor | Not available locally; Stockflow production host has neither | Database queue drained by the scheduler (see §3.6) |

### 1.4 Package compatibility (Packagist, 2026-09-04)

| Package | Version to use | Note |
|---|---|---|
| laravel/framework | `^13.0` (13.30.1) | PHP `^8.3` |
| filament/filament | `^5.0` (5.7.8) | includes actions, forms, schemas, tables, infolists, notifications, widgets |
| spatie/laravel-permission | `^8.0` (8.3.0) | |
| spatie/laravel-activitylog | `^4.12` — **not** 5.x | 5.x requires PHP `^8.4`, which breaks the PHP 8.3 production floor |
| bezhansalleh/filament-language-switch | `^5.0` | |
| bezhansalleh/filament-shield | — | **Not installed** (D-3: own Roles resource on spatie tables) |
| larastan/larastan | `^3.10` | |
| laravel/pint | `^1.27` | |
| phpunit/phpunit | `^12.5` — **not** 13.x | 13.x requires PHP 8.4.1 |
| guava/calendar | `^3.2` (MIT, requires `filament/filament ^5.0`) | evaluated; **not used** (D-12: custom calendar page) |
| relaticle/flowforge | `^4.1` (MIT, requires `filament/* ^5.0`) | evaluated; **not used** (D-12: custom kanban page) |
| mokhosh/filament-kanban | — | Filament 3 only; **rejected** |
| saade/filament-fullcalendar | 4.0.0-beta | beta only; **rejected** for production |
| eightynine/filament-reports | — | Filament 4 only; **rejected** |

---

## 2. Product scope

A single-organisation (D-2) sales CRM operated from one Filament admin panel.

| # | Module | Delivered capability |
|---|---|---|
| 1 | Dashboard | Permission-aware KPIs (leads by status, new/qualified/converted, open deals, weighted pipeline, won/lost, conversion rate, revenue won by month, my tasks today/overdue, upcoming follow-ups, stale deals, activity counts), date/owner/pipeline filters, charts |
| 2 | Leads | CRUD, status (configurable), source (configurable), owner, priority, score, qualification (status kind + qualified_at/by + history + notes), duplicate detection on email/phone, tags, notes, activities, tasks, attachments, timeline, bulk assign/status/tag/delete, import/export, conversion to Account + Contact + Deal |
| 3 | Lead sources / statuses | Configurable bilingual lookup tables with ordering, colours and behavioural `kind` |
| 4 | Contacts | CRUD, account link, job title, department, emails/phones, preferred locale, social link, owner, tags, notes, activities, tasks, attachments, timeline, related deals, duplicate detection, merge |
| 5 | Accounts (Companies / Customers) | One entity with a lifecycle `type` (D-6: prospect becomes customer on the first won deal): profile, industry (lookup), size, website, address, phone, email, owner, contacts, deals, activities, notes, attachments, timeline, tags, segmentation via tags + type + industry |
| 6 | Deals (Opportunities) | CRUD, account, contacts (with roles), owner, pipeline, stage, probability (stage default + override), amount computed from product line items in SAR (D-8), expected close date, source, product line items (D-8), forecast category, status open/won/lost, win/loss reason (configurable), competitors, stage history, activities, tasks, attachments, timeline, kanban board |
| 7 | Pipelines & stages | Configurable pipelines, ordered stages, probability, kind (open/won/lost), default stage, default pipeline, active flag |
| 8 | Activities | Calls, meetings, emails (logged), other; configurable activity types; owner, links to lead/contact/account/deal, occurred_at, duration, outcome, attachments, timeline |
| 9 | Tasks & follow-ups | Assignee, due date, priority, status, related records, reminders (in-app, mail when configured), completion, overdue detection, recurrence (daily/weekly/monthly), "my tasks" views |
| 10 | Calendar | Month/week/day views of tasks, meetings and follow-ups with create/edit from the calendar |
| 11 | Notes | Authored, timestamped, attached to lead/contact/account/deal, pinning, edit history (audit ledger) |
| 12 | Timeline | Chronological, per record: activities, notes, tasks, stage/status changes, ownership changes, attachments, conversion, audit events |
| 13 | Attachments | Private-disk uploads with MIME/size validation, metadata, authorised download, deletion rules, audit |
| 14 | Tags | Reusable bilingual tags across leads, contacts, accounts, deals; filtering by tag |
| 15 | Custom fields | Typed engine on Leads, Contacts, Accounts and Deals (D-9): field definitions per entity, typed values, forms, tables, filters, import/export |
| 16 | Search | Filament global search (⌘K) across leads, contacts, accounts, deals, tasks, scoped by visibility; per-table search and filters |
| 17 | Filtering | Advanced filters (incl. query builder) and table state (filters, sort, search) persisted per user in the session. Per-user saved views were built in step 9 and **removed on 2026-09-17**: the owner found the three list-page buttons of no use (A-8 amendment in [DECISIONS.md](DECISIONS.md)) |
| 18 | Import / Export | Import of CSV and of Excel workbooks (`.xlsx`, converted to CSV on upload; `.xls` is not readable and `.ods` is not read faithfully — OpenSpout returns every ODS boolean cell as true — so neither is offered) with column mapping, validation, duplicate handling, failed-rows report, history; CSV/XLSX export of tables and reports; permission-gated |
| 19 | Notifications | In-app bell (database notifications): assignment, task reminders, overdue, deal stage changes, mentions, import/export completion; mail channel only when a mailer is configured and the user opted in |
| 20 | Roles & permissions | spatie roles/permissions, six default roles, permission keys per verb (view/create/update/delete/export/import/assign/convert/change stage/manage settings/reports/audit/admin), policies enforced server-side |
| 21 | Teams & ownership | Teams, record owner, assignment with history, visibility own/team/all driven by permissions (D-4); reps cannot reassign |
| 22 | Audit logs | spatie activitylog ledger: create/update/delete diffs, ownership, status/stage, permission and settings changes, login events; read-only UI; retention policy |
| 23 | Reports | Lead, conversion/funnel, pipeline, sales performance, activity, source performance, win/loss, forecast, task performance; export from every report. The sales-performance report lists an owner only when they have deals open now or won or lost in the period (no all-zero lines), so a rep with nothing in the period sees an empty report |
| 24 | Settings | Lead statuses, sources, industries, pipelines/stages, activity types, close reasons, competitors, tags, custom fields, teams, general settings, notification preferences, user preferences (locale, theme) |
| 25 | Admin | Users (invite flow), roles, permissions, settings, audit, reports, import/export history |
| 26 | Platform | Arabic + English, RTL + LTR, light + dark + system, responsive, tests, static analysis, docs, CI/CD |

Out of scope for v1 (would need a separate decision and, mostly, a paid service): mailbox
synchronisation, WhatsApp/SMS, telephony, AI features, public web-to-lead forms, quotes/invoices,
multi-currency.

---

## 3. System architecture

### 3.1 Application architecture

- **Laravel 13, single Filament 5 panel** at `/admin` (`AdminPanelProvider`). No multi-panel layer,
  no workspace launcher. Resources and pages are discovered (`discoverResources`/`discoverPages`)
  with a structural test guaranteeing every discovered resource has a policy and permission keys.
- **Layers** (the Stockflow layering, kept): Filament Resources/Pages/Widgets (presentation only) →
  Policies (authorisation) → Services (business logic, transactions) → Models/Observers → Database.
  No business logic in Filament classes or controllers. Services are `final`, constructor-injected,
  and throw translated domain exceptions.
- **Resource composition**: `XResource` + `Schemas/XForm`, `Schemas/XInfolist`, `Tables/XsTable`,
  `Pages/{List,Create,View,Edit}X`, `RelationManagers/*`. Labels only through `get*Label()` methods.
  Shared action factories (`Filament/Support/LeadActions`, `DealActions`, `TaskActions`) so table
  actions and view-page header actions share one implementation.
- **Workflows** (`Services/Leads/LeadStatusWorkflow`, `LeadConversionWorkflow`,
  `Services/Deals/DealStageWorkflow`, `Services/Tasks/TaskCompletionService`): status/stage
  transitions run inside `DB::transaction`, lock the row, validate the transition against the
  configurable stage/status rows, write the append-only history row, write the audit event and
  dispatch notifications. Workflow-owned columns are guarded by an observer so they cannot be edited
  through a form.
- **Read models**: `Services/Activities/TimelineReader` (unions activities, notes, tasks, stage/status
  logs, attachments and audit events into `TimelineEntry` DTOs), `Services/Statistics/*` (aggregate
  SQL, user-scoped), `Services/Access/RecordVisibilityResolver`.
- **Configurable business data as rows**, never enums: lead statuses, lead sources, industries,
  pipelines, stages, activity types, close reasons, competitors, tags, teams, custom fields. Code
  enums only for behaviour that code must reason about (`LeadStatusKind`, `StageKind`,
  `ActivityKind`, `TaskStatus`, `TaskPriority`, `DealStatus`, `ForecastCategory`, `UserStatus`,
  `Permission`, `NavigationGroup`, `ActivityLogEvent`).
- **Conventions**: `declare(strict_types=1)` and `final` everywhere (guard test over `app/`,
  `database/`, `tests/`), `#[Fillable]`/`#[Hidden]`/`#[ObservedBy]` attributes, `casts()` method,
  `@property` docblocks for every enum/date cast (Larastan `checkModelProperties`), relation generics.

### 3.2 Database architecture

MySQL 8.4 locally and in CI; production engine confirmed in writing before the first production migration (D-1).
The migrations are proven on MySQL 8.4.11 and MariaDB 10.4.32 (fresh migrate + seed, full rollback, re-migrate, double
seed, preflight, and a full suite with no engine-difference failure) through the engine-aware `App\Support\Database\DatabaseEngine` helper (A-21).
Full schema in [DATABASE_DESIGN.md](DATABASE_DESIGN.md). Rules:

- One table per migration, docblock stating purpose and decision reference, reversible `down()`.
- InnoDB, `utf8mb4_unicode_ci`, snake_case plural tables, `id` bigint PK, `created_at/updated_at`,
  `deleted_at` (soft deletes) on every business entity, never on ledgers/pivots.
- Every FK explicit with `restrictOnDelete()` by default; `cascadeOnDelete()` only for owned children
  (pivots, values, logs of a hard-deleted parent); `nullOnDelete()` for optional references to users.
- Code-enum columns are `string(32)` with a DB `CHECK` written through `App\Support\Database\EnumCheck`
  (engine-aware so a MariaDB host does not break `DROP CHECK`).
- Indexes on every FK, on `owner_id`, `(status/stage, owner)` pairs used by dashboards, on
  `email_normalized`/`phone_normalized` (duplicate detection), on `due_at`/`reminder_at`,
  `occurred_at`, and on the `activity_log` subject/causer + `created_at`.
- Money as `DECIMAL(14,2)`; probabilities `TINYINT UNSIGNED`; JSON only where the payload is
  genuinely schemaless (saved-view filters, notification data, audit properties, custom-field option
  lists). Custom field **values** are typed columns, not JSON.
- Append-only tables (`deal_stage_logs`, `lead_status_logs`, `activity_log`) protected by observers
  that throw on update/delete; DB triggers only if compliance requires them later.

### 3.3 Authorization architecture

- spatie/laravel-permission roles and permissions on the `web` guard. Permission keys are typed in
  `App\Enums\Permission` (`lead.view_any`, `lead.view_team`, `lead.view_all`, `lead.create`,
  `lead.update`, `lead.delete`, `lead.restore`, `lead.assign`, `lead.convert`, `lead.export`,
  `lead.import`, … `deal.change_stage`, `settings.manage`, `reports.view`, `audit.view`,
  `users.manage`, `roles.manage`). `saved_view.share` was dropped with the saved-views feature on
  2026-09-17 (A-8 amendment in [DECISIONS.md](DECISIONS.md)).
- Default role→permission sets live in `App\Support\Access\RolePermissionMatrix` and are seeded by
  `RolesAndPermissionsSeeder` (idempotent, `syncPermissions`, cache flushed). A drift test asserts
  the seeded tables equal the matrix; runtime edits through the Roles resource are audited (D-3).
- `super_admin` is granted every permission explicitly by `RolePermissionMatrix` and the seeder, and the role is locked (no `Gate::before`). Roles: `super_admin`, `admin`, `sales_manager`,
  `sales_rep`, `support`, `read_only`.
- Policies: one per model, `Policies\Concerns\ChecksPermissions` gives `viewAny/create/update/delete/
  restore/forceDelete` plus explicit `deleteAny/restoreAny/forceDeleteAny` (Filament grants a missing
  policy method, so they are always defined) and `forceDelete = false`. Record-level checks combine the
  permission with `RecordVisibilityResolver::canRead/canWrite(User, Model)`.
- Visibility (D-4): `RecordVisibilityResolver::visible(User, Builder)` applies Own / Team / All
  from the user's permissions; applied in every resource `getEloquentQuery()`, global search query,
  exporters, widgets and statistics services. Filters never widen scope.
- Filament enforcement: resource `can*` methods (policy-backed), `->authorize()` on actions,
  `authorizeIndividualRecords()` on every bulk action, `Page::canAccess()`, `Widget::canView()`,
  `Importer`/`Exporter` policies. `->strictAuthorization()` on the panel.
- Authentication: Filament login (rate-limited), invitation-only user creation (password set through a
  signed reset link, status `pending → active`), status gating in `canAccessPanel()`, profile page,
  MFA available to all users and optional (D-11), password policy per D-11, no self-registration, no API.

### 3.4 UI architecture

- One Filament panel; navigation groups from `App\Enums\NavigationGroup` (Sales, Contacts, Activities,
  Reports, Settings, System) with closure labels so the sidebar re-translates on locale switch.
- Standard pages per resource; record 360 views (`ViewLead`, `ViewAccount`, `ViewDeal`) with tabs:
  overview, timeline, activities, tasks, notes, attachments, related records.
- Custom pages: `Dashboard` (Filament dashboard with filters form), `DealBoard` (kanban),
  `Calendar`, `Reports/*`, `Settings/General`.
- Tables: `defaultSort` on every table (guard test), persisted filters/sort/search in session,
  column manager, list tabs, query-builder filter on the main lists, empty states translated.
- Light/dark/system: `->darkMode()->themeSwitcher()->defaultThemeMode(ThemeMode::System)`; one Tailwind 4
  theme file with `:root` tokens redefined under `.dark`; every custom page/widget uses Filament
  components so dark mode is inherited; charts read colours from CSS variables.
- Responsive: Filament layouts; custom kanban and calendar tested at mobile widths.

### 3.5 Localization architecture

- `lang/ar/*.php` and `lang/en/*.php`, one file per module (`leads.php`, `deals.php`, …) with the
  fixed sub-key vocabulary (`navigation`, `sections`, `fields`, `placeholders`, `helpers`,
  `validation`, `filters`, `actions`, `confirmations`, `notifications`, `empty`, `pages`), enum labels
  only in `enums.php`, Laravel `auth/passwords/validation/pagination` in both locales.
- No hardcoded user-facing string in any language (guard tests: parity, Arabic-script-in-English,
  unused keys, literal `->label('…')` scanner).
- Arabic is the default locale, English the fallback (D-5). `users.locale` persisted from
  the language switch; `contacts.preferred_locale` for outbound mail; notifications rendered in the
  recipient's locale.
- RTL/LTR from Filament's direction handling; theme uses logical properties; no `[dir='ltr']` exceptions;
  Latin-only values (emails, URLs, phone numbers) wrapped `dir="ltr"`; kanban/calendar receive
  direction and translated labels from the server.
- Bilingual `name_ar`/`name_en` (both required) on configurable lookups only; free-text entity names
  are single columns.

### 3.6 Notification architecture

As built (step 12, read from `app/Notifications` and `routes/console.php` on 2026-09-15).

- Laravel notifications with Filament's database envelope (`FilamentNotification::make()->…`), panel
  `->databaseNotifications()->databaseNotificationsPolling('30s')`, actions inside notifications (open record).
- Notification classes (`app/Notifications`):

  | Class | Sent by | To | Queued |
  |---|---|---|---|
  | `RecordAssignedNotification` | `RecordAssignmentService` (owner changes from the edit pages, the assign actions, task assignment and import rows that reassign an existing record) | the new owner / assignee | no |
  | `DealStageChangedNotification` | `DealStageWorkflow` (open-stage move or reopen by someone else) | the deal owner | yes |
  | `DealClosedNotification` | `DealStageWorkflow` (won / lost) | the owner and the owner's team manager, never the actor | yes |
  | `LeadConvertedNotification` | `LeadConversionWorkflow` (after commit, conversion by someone else) | the lead owner | yes |
  | `LeadStaleNotification` | `LeadStaleService` through `leads:notify-stale` | the owner of a quiet open lead, once | yes |
  | `NoteMentionNotification` | `NoteService` | every mentioned user except the author | yes |
  | `TaskReminderNotification` | `TaskReminderService` through `tasks:send-reminders` | the assignee, once | no |
  | `TaskOverdueNotification` | `TaskReminderService` through `tasks:notify-overdue` | the assignee, once | no |
  | `UserInvitationNotification` | `UserInvitationService` (D-11) | the invited user, mail only | no |

  Import and export completion notices are Filament's own database notifications. Templated e-mail (D-10)
  is the queued mailable `App\Mail\CrmMessage` sent by `EmailSendService`, not a notification.
- Recipients: `Services/Notifications/NotificationRecipients` keeps only users who can open the record and
  can sign in (never disabled or pending accounts); a team manager is chosen among the team's active members.
- Channels: every `via()` except the invitation goes through `App\Support\Notifications\NotificationChannels::for()`:
  `database` unless the user switched the event off; `mail` only when `config('mail.default')` is a real
  transport (not `log`, `array` or empty) and the user's `notification_preferences` row opts in. No paid provider.
- Queue: `QUEUE_CONNECTION=database`, drained by the scheduler every minute, `sync` in tests. Fixed by D-1
  (shared hosting, no persistent worker).
- Scheduler (`routes/console.php`; wiring pinned by `tests/Feature/Notifications/SchedulerWiringTest.php` and
  `tests/Feature/System/ProductionWiringTest.php`). Every entry runs `onOneServer()`:

  | Entry | Cadence | Purpose |
  |---|---|---|
  | `queue:work --stop-when-empty --max-time=50` | every minute, `withoutOverlapping(10)`, skipped when the queue driver is `sync` | drains the database queue (D-1) |
  | `scheduler:heartbeat` (named closure) | every minute | stores the time under the cache key `scheduler.heartbeat` for ten minutes; `app:preflight` warns when it is missing or older than five minutes (the host's cron is not running `schedule:run`) |
  | `tasks:send-reminders` | every five minutes, `withoutOverlapping(5)` | task reminders, idempotent via `reminder_sent_at` (A-10) |
  | `tasks:notify-overdue` | every fifteen minutes, `withoutOverlapping(5)` | overdue notices, idempotent via `overdue_notified_at` |
  | `leads:notify-stale` | daily at 07:00 (app timezone) | stale-lead notice, idempotent via `stale_notified_at` |
  | `RescoreLeads` (queued job) | daily at 03:00 | recomputes open lead scores in batches of 500 so activity-recency points expire (D-7); unique, `$timeout = 45` so one batch fits one drain |
  | `attachments:prune-temporary` (named closure) | daily | deletes uploads parked under `tmp/` on the attachments disk for more than a day |
  | `uploads:prune-livewire-temporary` (named closure) | daily | deletes Livewire temporary uploads (import files included) older than a day under `livewire.temporary_file_upload.directory` (`livewire-tmp`) on the upload disk; Livewire itself clears them only when the next upload starts (D-13) |
  | `activitylog:clean --days=<crm.audit.retention_days> --force` | weekly | audit retention, 730 days by default (D-13) |
  | `queue:prune-failed --hours=168` | weekly | failed jobs older than seven days |
  | `model:prune --model=App\Models\Import --model=App\Models\Export` | daily | import history after `Import::RETENTION_DAYS` (90), export history and files after `Export::RETENTION_DAYS` (30); failed import rows leave with their import through the cascading key |
  | `reports:prune-downloads` (named closure) | hourly | deletes report export files older than an hour that an aborted download stranded |

### 3.7 Audit architecture

- spatie/laravel-activitylog as the ledger (`activity_log`), app model `App\Models\ActivityLog`,
  `LogsActivity` with an explicit `logOnly` whitelist and `logOnlyDirty` on every audited model,
  exhaustive `getDescriptionForEvent`, plus service-written business events through
  `Services/Audit/<Domain>ActivityLogger` with an `ActivityLogEvent` enum (`lead.assigned`,
  `lead.converted`, `deal.stage_changed`, `user.role_changed`, `role.permissions_changed`,
  `settings.updated`, `auth.login`, `auth.failed`, `attachment.downloaded`, …).
- Append-only observer; policy denies update/delete/restore; `audit.view` permission; read-only
  resource with filters (causer, subject, event, date) and a before/after diff modal; retention via
  `activitylog:clean --days=<config>` (default 730).
- Secrets never logged (whitelists + a test that scans the ledger for secret keys).

### 3.8 Search architecture

- Filament global search on Lead, Contact, Account, Deal, Task only (A-8, A-21): every other resource sets
  `protected static bool $isGloballySearchable = false`, pinned by
  `CompletenessProbeTest::global_search_is_offered_only_on_the_five_resources_plan_section_3_8_names`;
  `getGloballySearchableAttributes()`
  (name, email, phone, company, title), result details/actions, `getGlobalSearchEloquentQuery()`
  through the visibility resolver, `canGloballySearch()` = `canViewAny()`; key bindings ⌘K/Ctrl+K;
  debounce 500 ms; negative test that out-of-scope records never surface.
- Table search on indexed columns; normalised email/phone columns for exact duplicate lookups.

### 3.9 Reporting architecture

- Dashboard: Filament `Dashboard` with `HasFiltersForm` (date range, owner/team, pipeline), widgets
  (`StatsOverviewWidget`, `ChartWidget` bar/line/doughnut, `TableWidget`) each gated by `canView()` and
  fed by user-scoped statistics services.
- Reports: `Filament\Pages\Page` classes under `Filament/Pages/Reports/`, each backed by a
  `Services/Statistics/*Analytics` or `*ReportQuery` service (aggregate SQL with `ONLY_FULL_GROUP_BY`
  compatible grouping), table + chart, `ExportAction`, `reports.view` permission plus the visibility
  resolver.

### 3.10 Integration architecture

- v1 has no external integrations. The only seams are Laravel's mailer (SMTP configured on the server,
  `log` locally) and the filesystem disk. A documented provider-seam pattern (contract + reason-keyed
  exception + `*:diagnose` command) is reserved for later channels (mail sync, WhatsApp, SMS) and will
  be introduced only with the owner's approval and cost decision.

### 3.11 Testing architecture

See §6.

---

## 4. Module map and dependencies

```
Foundation (auth, users, roles/permissions, teams, settings infra, lang, theme, audit base, tests base)
   │
   ├─► Lookups (lead statuses, lead sources, industries, pipelines+stages, activity types,
   │            close reasons, competitors, tags)
   │        │
   │        ├─► Accounts ──► Contacts
   │        │                   │
   │        ├─► Leads ◄─────────┘ (duplicate detection needs contacts/accounts)
   │        │     │
   │        ├─► Deals (needs accounts, contacts, pipelines, close reasons, competitors)
   │        │     │
   │        └─────┴─► Lead conversion (needs leads + accounts + contacts + deals)
   │
   ├─► Activities, Notes, Tasks, Attachments (need every subject entity)
   │        └─► Timeline (needs all of the above + audit ledger)
   │        └─► Calendar (tasks + meetings)
   │        └─► Reminders & notifications (tasks + queue/scheduler)
   │
   ├─► Search, filters, import/export (need the entities and visibility resolver)
   ├─► Dashboard & reports (need deals, leads, activities, tasks, statistics services)
   ├─► Audit UI (needs ledger instrumentation from every module)
   └─► Custom fields (needs entities; wired into forms, tables, filters, import/export)
```

---

## 5. Implementation order

Each step is designed → implemented → validated → authorised → tested (PHPUnit) → reviewed
(PHPStan + Pint) → integrated → committed before the next dependent step starts.

| Step | Scope | Exit criteria |
|---|---|---|
| 0 | **Scaffold**: Laravel 13 + Filament 5 via Herd PHP; `.env.example`; `crm`/`crm_testing` databases; Herd link; composer scripts `lint/analyse/test/check`; PHPStan + Pint config; `phpunit.xml` on MySQL; CI workflow; README/CLAUDE.md/CONTRIBUTING.md; Git | `composer check` green on an empty app; site opens at `http://crm.test/admin` |
| 1 | **Foundation**: panel provider (dark mode, theme switcher, database notifications, global search, SPA, font), Tailwind 4 theme + tokens + dark tokens, `lang/{ar,en}` base files + guard tests, `NavigationGroup`, User model (status, locale, team), invitation flow, login/profile/MFA wiring, spatie permission + `Permission` enum + matrix + seeder + drift test, teams, `RecordVisibilityResolver`, `ChecksPermissions` policy trait, activitylog config + `ActivityLog` model + append-only observer + auth listeners, `EnumCheck` helper, `settings` table + service, `app:onboard`, `app:preflight`, fixtures trait, base structural tests | Users resource, Roles resource, Teams resource, Settings page and Audit log page working in both locales/directions/themes with tests |
| 2 | **Lookups**: lead statuses, lead sources, industries, pipelines + stages (reorderable), activity types, close reasons, competitors, tags; default seeders | Settings navigation complete, all CRUD authorised and tested |
| 3 | **Accounts & Contacts**: models, resources, 360 view pages, relation managers, duplicate detection + merge, tags, owner/assign, audit instrumentation | Feature + policy + visibility + audit tests |
| 4 | **Leads**: model, resource, status workflow + history, qualification, rule-based scoring with manual override (D-7), duplicate detection, bulk actions, tags, assignment notifications | Tests incl. transitions and scoring |
| 5 | **Deals & pipelines**: model, resource, stage workflow + `deal_stage_logs`, won/lost with reasons, competitors, contacts with roles, product line items (D-8), kanban board, forecast fields | Tests incl. transition matrix, kanban moves, guard observer |
| 6 | **Lead conversion**: `LeadConversionWorkflow` (account/contact/deal creation or linking, transactional), conversion UI, audit + timeline entries, notifications | Conversion tests incl. rollback |
| 7 | **Activities, notes, tasks, attachments, timeline**: models, relation managers on every subject, `ActivityRecorder`, `TimelineReader` + timeline component, tasks with recurrence + reminders + overdue, `AttachmentStorage` + download route, calendar page | Tests incl. reminders with `travelTo`, download authorisation, recurrence |
| 8 | **Notifications & scheduler**: notification classes, preferences, mail gating, bell actions, `routes/console.php` schedule, production-wiring tests | Notification tests (`Notification::fake`) |
| 9 | **Search, filters, import/export**: global search config, query-builder filters, Filament importers/exporters, import/export history resources, prune schedule (saved views were built here and removed on 2026-09-17 as unused — A-8 amendment) | Scope tests, importer tests with fixture CSVs |
| 10 | **Dashboard & reports**: statistics services, widgets, charts, nine report pages with export | Report query tests against seeded data, permission tests |
| 11 | **Custom fields** (D-9, all four entities): definitions, typed values, dynamic form/table/filter components, import/export columns | Tests per field type |
| 12 | **Quality pass**: security, performance (N+1 with `preventLazyLoading` in tests, indexes, query counts), RTL/LTR walk, dark/light walk, translation audit, DB review, authorisation matrix walk, test review | Checklist in `docs/GoLive_Checklist.md` complete |
| 13 | **Production readiness**: clean install from scratch, migrations fresh + seed, CI green, `filament:optimize`, deployment runbook, `.env` documentation, demo data command | Acceptance criteria in the master prompt §24 |

---

## 6. Testing strategy

> Quality pass (2026-09-14): `phpunit.xml` sets `failOnWarning`, `failOnRisky`, `failOnDeprecation` and `failOnPhpunitDeprecation`, so a deprecation raised only on the PHP 8.3 CI runner or the 8.4 local runner fails the build. Per-role authorisation matrices for every owned entity live in `tests/Feature/Access/OwnedPolicyMatrixTest.php`. Shared fixtures live in `Tests\Concerns\CreatesCrmFixtures` and upload byte builders in `Tests\Concerns\BuildsUploadBytes`. Lazy loading is prevented for the whole suite in `tests/TestCase.php`. `Storage::fake()` uses `storage/framework/testing/disks/<disk>` for every process, so two PHPUnit runs at the same time wipe each other's fake files even on different databases — run suites that touch the fake disk one at a time.

- **Framework**: PHPUnit 12 with `#[Test]` attributes, `final` classes, snake_case sentence names,
  `RefreshDatabase` against MySQL `crm_testing`, `Filament::setCurrentPanel('admin')` before Livewire
  tests, `Notification::fake()`, `Queue::fake()`, `Storage::fake()`, `$this->travelTo()` for time logic.
- **Fixtures**: `tests/Concerns/CreatesCrmFixtures` (typed helpers creating users per role, teams,
  lookups, leads, accounts, contacts, deals) plus model factories for bulk data.
- **Families per module**:
  - Unit: services (workflows, resolver, timeline reader, scoring, recurrence, money rounding).
  - Feature/CRUD: `Livewire::test(ListX)->assertCanSeeTableRecords`, `CreateX->fillForm->call('create')
    ->assertHasNoFormErrors`, `EditX`, `ViewX`, relation managers.
  - Validation: required/format/unique/bilingual-pair rules, custom-field validation.
  - Authorization: per-role `$user->can()` matrices, HTTP 403 tests with a `DataProvider`, bulk action
    `authorizeIndividualRecords`, global search scope, export scope, widget `canView`.
  - Visibility isolation: own/team/all across every owned entity.
  - Business rules: status/stage transitions, conversion, qualification, duplicate detection, merge,
    reminders, overdue, recurrence, won/lost rules.
  - Database: `information_schema` assertions for FKs/uniques/CHECKs, CHECK rejection tests,
    append-only guards.
  - Imports/exports: fixture CSVs, mapping, failed rows, permissions, scope.
  - Notifications: sent to the right users, right locale, mail gating.
  - Reports: seeded data → expected aggregates, permission scoping.
  - Localization: parity, Arabic-script-in-English, unused keys, literal-label scanner, default locale,
    RTL direction attribute.
  - Structural guards: strict_types/final, every resource has a policy + permission keys, every table
    has `defaultSort`, every owned model applies the visibility trait, `delete(null)=false ⇒ deleteAny`
    override present, seeded matrix has no drift.
  - System: production wiring (schedule entries, throttles, preflight), deployment configuration
    string-asserts, seeder idempotency.
- **Gates**: `composer check` (Pint → PHPStan level 5 → PHPUnit) locally and in CI on every push and
  pull request; PHPStan has no baseline and no inline ignores (false positives documented in
  `docs/Static_Analysis_Known_False_Positives.md`).

---

## 7. Security plan

| Area | Measure |
|---|---|
| Authentication | Filament login with rate limiting; invitation-only accounts; status gating; `Password::defaults()` (min 12, mixed case, numbers, uncompromised — D-11); MFA providers wired, optional for all users (D-11); session lifetime/secure cookie in production; login/logout/failed events audited; `last_login_at` |
| Authorization | Policies for every model incl. lookups and settings; permission keys enumerated; no `Gate::before`; `super_admin` holds the full catalogue explicitly; `->strictAuthorization()`; bulk `authorizeIndividualRecords`; visibility resolver in every query path; importers/exporters/widgets/pages gated |
| Record ownership | `owner_id` on leads/contacts/accounts/deals/tasks; assignment permission; every change of owner — edit pages, assign actions, task assignment and import rows that reassign an existing record — goes through `RecordAssignmentService`, which audits (`{entity}.assigned`) and notifies the new owner |
| Mass assignment | `#[Fillable]` whitelists; workflow columns guarded by observers; outside production `AppServiceProvider::configureModels()` enables `Model::preventSilentlyDiscardingAttributes()` and `Model::preventAccessingMissingAttributes()` only — lazy-loading prevention is not enabled in the application (spatie/laravel-permission and Filament resolve relations lazily) and is switched on for the whole test suite in `tests/TestCase.php` |
| Validation | Filament schema rules + `Rule::unique` closures + custom rules for phone/email normalisation; server-side only |
| CSRF / sessions | Filament middleware stack (`PreventRequestForgery`, `AuthenticateSession`); `SESSION_SECURE_COOKIE=true` in production (preflight fails otherwise) |
| Files | Private disk, server-side MIME sniffing allowlist, size caps from config, UUID names, per-entity directories, authorised download route, `FileUpload::preventFilePathTampering()` |
| XSS | Blade escaping; `RichEditor` JSON content rendered through Filament's sanitising renderer; `Str::sanitizeHtml` on any HTML output |
| SQL injection | Eloquent/bindings only; raw aggregates parameterised |
| Sensitive data | No secrets in the ledger; `#[Hidden]`; encrypted casts for any future integration credentials; no IDs/credentials in notifications |
| Rate limiting | `throttle` on every public POST (password reset), Filament login limiter, `rateLimit()` on heavy actions (import, export) |
| Audit | Ledger per §3.7 |
| Errors | `APP_DEBUG=false` enforced by preflight; friendly translated error pages — done: `resources/views/errors/{403,404,419,429,500,503}.blade.php` share one template with no build, session or database dependency, strings in `lang/*/errors.php` |
| Headers | Security headers middleware (nosniff, frame-deny, referrer policy, HSTS when https) |

---

## 8. Performance plan

> Quality pass (2026-09-14): `SettingsRepository` and `CustomFieldRegistry` are container-scoped (one memo per request, job or command) and forgotten whenever a setting or a definition is saved; date filters compare raw columns against organisation-day bounds instead of `whereDate()`; import and export actions are rate limited (5 and 10 per minute); the rescore job reads 500 whole leads per batch and queues its continuation. Production readiness (2026-09-15): the calendar reads tasks as three bounded index ranges (starting inside the range, running into it, due inside it); the running-into slice is bounded only by `ends_at`, so a month far in the past reads the timed tasks that end after it — accepted, because the current and coming months stay bounded; the lead and deal list tabs and counts use `leads (deleted_at, lead_status_id)` and `deals (status, deleted_at, created_at)`.

- Eager loading declared per table (`modifyQueryUsing(fn ($q) => $q->with([...]))`), `preventLazyLoading`
  in tests so N+1s fail the suite.
- Indexes per [DATABASE_DESIGN.md](DATABASE_DESIGN.md); dashboard/report queries are aggregate SQL, not
  collection loops; optional `Cache::remember` (file cache) for dashboard KPIs with short TTL.
- Pagination everywhere; kanban limits cards per column with "load more" (capped, `DealBoardRenderBoundsProbeTest`); calendar loads by
  visible range (at most 62 days) and returns at most `CalendarFeed::MAX_EVENTS` (500) entries per range, the earliest across tasks
  and activities, with a translated hint when the range was cut.
- Imports chunked (100 rows) and queued; exports chunked and queued; both prunable.
- Global search limited per resource; indexed columns only.
- `filament:optimize`, config/route/view cache in deploys.

---

## 9. Deployment readiness

Step 13 status (2026-09-15). The runbook is [DEPLOYMENT.md](DEPLOYMENT.md), day-2 operations are in
[OPERATIONS.md](OPERATIONS.md); open go-live items (host, owner and evidence runs) are tracked in
[GoLive_Checklist.md](GoLive_Checklist.md).

- Target host is Hostinger shared hosting (D-1). Built: `.github/workflows/deploy.yml`, run by hand
  (`workflow_dispatch` with a release note): a PHP 8.3 + Node 22 job builds `vendor/` without dev packages and
  `public/build` into a release tarball artifact; an optional job, active only when the `DEPLOY_SSH_HOST`,
  `DEPLOY_SSH_USER`, `DEPLOY_SSH_KEY` and `DEPLOY_PATH` secrets exist, uploads it with rsync into a release folder and
  runs maintenance mode, `migrate --force`, `db:seed --force`, `optimize:clear`, `optimize`, `filament:optimize`,
  `app:preflight`, the `current` switch and `up` on the host. The release commands live inline in the workflow; the
  `scripts/deploy-production.sh` of the step-0 proposal was not needed. One cron running `schedule:run` every minute
  drives the schedule of section 3.6; `.env` is set on the server only; no `storage:link` is needed (nothing is served
  from the public disk). If a VPS: same pipeline plus Supervisor for `queue:work`.
- Built: trusted proxies through `CRM_TRUSTED_PROXIES` (`App\Http\Middleware\TrustProxies`, read per request),
  `tests/Feature/System/TrustedProxiesTest.php`.
- Built: `.github/workflows/ci.yml` (Pint, PHPStan, asset build and PHPUnit on PHP 8.3 + MySQL 8.4).
- Built: `app:preflight` (`tests/Feature/System/PreflightCommandTest.php`). Fails everywhere on PHP older than 8.3 or a
  missing required extension, a blank `APP_KEY`, an attachments disk under `public/`, unwritable `storage/app`,
  `storage/logs` or `bootstrap/cache`, an unreachable or unmigrated database, pending migrations, an incomplete
  permission catalogue, no active super admin and missing reference rows (default lead status, converted status,
  default pipeline and its default stage, system activity types); in production on `APP_DEBUG=true`, a non-https
  `APP_URL`, `SESSION_SECURE_COOKIE≠true`, a `sync` queue and the `array` cache. Warns on the `log` / `array` mailer
  and a missing or stale scheduler heartbeat, and in production on `SESSION_LIFETIME≠120`, uncached configuration,
  routes, views or Filament components, and unset trusted proxies. `--json` for scripts.
- Built: `app:demo-data` (`--force`, `--fresh`, `--scale`), refused in production
  (`tests/Feature/System/DemoDataCommandTest.php`); `.env.example` lists every key the configuration reads
  (`tests/Feature/System/EnvExampleTest.php`).
- Documentation set built: README, CLAUDE.md, CONTRIBUTING.md, `docs/ARCHITECTURE_PLAN.md`, `DATABASE_DESIGN.md`,
  `DECISIONS.md`, `PERMISSIONS.md`, `GoLive_Checklist.md`, `STOCKFLOW_COMPARISON.md`, `OPEN_DECISIONS.md`,
  `DEPLOYMENT.md` (runbook and `.env` reference), `OPERATIONS.md`, `Static_Analysis_Known_False_Positives.md` (no
  entries). `tests/Feature/System/DeploymentDocsTest.php` keeps DEPLOYMENT.md and OPERATIONS.md in step with the
  schedule, the artisan commands and `.env.example`. Not built: `MODULES.md`, `DESIGN_TOKENS.md`.

---

## 10. Proposed folder structure

The tree below is the step-0 proposal, kept for orientation; the code is the authority for class names. The console
commands, job, notification, docs and workflow lines are as built (step 13).

```
CRM_Project/
├── app/
│   ├── Console/Commands/          OnboardCommand (app:onboard), PreflightCommand (app:preflight), SendTaskReminders (tasks:send-reminders),
│   │                              NotifyOverdueTasks (tasks:notify-overdue), NotifyStaleLeads (leads:notify-stale), DemoDataCommand (app:demo-data)
│   ├── Contracts/                 TranslatableStatus
│   ├── Enums/                     NavigationGroup, Permission, CrmRole, UserStatus, LeadStatusKind, LeadPriority, StageKind, DealStatus,
│   │                              ForecastCategory, ActivityKind, ActivityDirection, TaskStatus, TaskPriority, RecurrenceFrequency,
│   │                              AccountType, CompanySize, CustomFieldType, ActivityLogEvent, NotificationEvent
│   ├── Exceptions/{Leads,Deals,Tasks,Attachments,Imports,Access}/
│   ├── Filament/
│   │   ├── Auth/Pages/            ResetPassword, RequestPasswordReset (pending users may request)
│   │   ├── Concerns/              ScopesQueriesToVisibleRecords, RunsWorkflowActions, ChecksPermission
│   │   ├── Exports/               LeadExporter, ContactExporter, AccountExporter, DealExporter, ActivityExporter, TaskExporter
│   │   ├── Imports/               LeadImporter, ContactImporter, AccountImporter, DealImporter
│   │   ├── Pages/                 Dashboard, DealBoard, Calendar, Settings/GeneralSettings,
│   │   │   ├── Reports/           LeadReport, ConversionReport, PipelineReport, SalesPerformanceReport, ActivityReport,
│   │   │   │                      SourcePerformanceReport, WinLossReport, ForecastReport, TaskPerformanceReport
│   │   │   └── Concerns/          HasReportFilters
│   │   ├── Resources/             Leads/, Contacts/, Accounts/, Deals/, Activities/, Tasks/, Notes/ (relation-only), Attachments/ (relation-only),
│   │   │                          Pipelines/ (+StagesRelationManager), LeadStatuses/, LeadSources/, Industries/, ActivityTypes/, CloseReasons/,
│   │   │                          Competitors/, Tags/, Teams/, CustomFields/, Users/, Roles/, ActivityLogs/, Imports/, Exports/
│   │   │                          (each: XResource, Schemas/, Tables/, Pages/, RelationManagers/)
│   │   ├── Support/               LeadActions, DealActions, TaskActions, ContactActions, SharedSchemas (address, owner, tags, custom fields)
│   │   └── Widgets/               SalesKpisWidget, LeadFunnelWidget, PipelineByStageChart, WonRevenueTrendChart, MyTasksTodayWidget,
│   │                              OverdueTasksWidget, UpcomingFollowUpsWidget, StaleDealsWidget, RecentLeadsWidget, ActivityFeedWidget
│   ├── Http/Controllers/          AttachmentDownloadController
│   ├── Jobs/                      RescoreLeads
│   ├── Listeners/                 ActivateInvitedUser, RecordAuthActivity, PersistUserLocale, RecordLastLogin
│   ├── Models/
│   │   ├── Concerns/              HasLocalisedName, HasOwner, HasTags, HasAttachments, HasNotes, HasTasks, HasActivities, HasCustomFieldValues, GuardsWorkflowFields
│   │   └── *.php                  User, Team, Setting, Lead, LeadStatus, LeadStatusLog, LeadSource, Industry, Account, Contact, Deal, DealStageLog,
│   │                              DealContact, DealCloseReason, Competitor, Product, DealProduct, Pipeline, PipelineStage, ActivityType,
│   │                              Activity, Task, Note, Attachment, Tag, CustomField, CustomFieldValue, LeadScoringRule, EmailTemplate, NotificationPreference, ActivityLog
│   ├── Notifications/             RecordAssignedNotification, TaskReminderNotification, TaskOverdueNotification, DealStageChangedNotification,
│   │                              DealClosedNotification, LeadConvertedNotification, LeadStaleNotification, NoteMentionNotification,
│   │                              UserInvitationNotification
│   ├── Observers/                 LeadObserver, DealObserver, TaskObserver, AttachmentObserver, PipelineStageObserver,
│   │                              DealStageLogAppendOnlyObserver, LeadStatusLogAppendOnlyObserver, ActivityLogAppendOnlyObserver
│   ├── Policies/                  <Model>Policy per model + Concerns/ChecksPermissions
│   ├── Providers/                 AppServiceProvider, Filament/AdminPanelProvider
│   ├── Services/
│   │   ├── Access/                RecordVisibilityResolver, RoleService
│   │   ├── Accounts/ Contacts/    AccountService, ContactService, DuplicateFinder, RecordMerger
│   │   ├── Activities/            ActivityRecorder, TimelineReader, TimelineEntry
│   │   ├── Attachments/           AttachmentStorage, AttachmentService
│   │   ├── Audit/                 ActivityLogQuery, ActivityLogPresenter, LeadActivityLogger, DealActivityLogger, AccessActivityLogger, SettingsActivityLogger
│   │   ├── CustomFields/          CustomFieldSchemaBuilder, CustomFieldValueWriter
│   │   ├── Deals/                 DealStageWorkflow, DealCloseService, DealAmountCalculator, ForecastReader
│   │   ├── Leads/                 LeadStatusWorkflow, LeadConversionWorkflow, LeadAssignmentService, LeadScoringService
│   │   ├── Notifications/         NotificationDispatcher, ChannelResolver
│   │   ├── Settings/              SettingsRepository, LookupOrderingService, PipelineService
│   │   ├── Statistics/            DashboardMetrics, LeadFunnelMetrics, DealAnalytics, ActivityAnalytics, TaskAnalytics, SourceAnalytics, ForecastAnalytics
│   │   ├── Tasks/                 TaskService, TaskRecurrenceService, TaskReminderEvaluator, ReminderOutcome
│   │   └── Users/                 UserInvitationService
│   └── Support/
│       ├── Access/                RolePermissionMatrix
│       ├── Database/              EnumCheck, DatabaseEngine, TimestampRange
│       ├── Filament/              FilamentLanguageMenuItems
│       ├── Money.php              deal line totals (D-8)
│       └── PhoneNumber.php        normalisation
├── bootstrap/                     app.php, providers.php
├── config/                        admin.php, brand-colors.php (generated), crm.php, activitylog.php, permission.php, filament.php
├── database/                      migrations/ (one table per file), seeders/ (DatabaseSeeder, RolesAndPermissionsSeeder, LookupSeeder,
│                                  DefaultPipelineSeeder; Demo/ builders for app:demo-data), factories/
├── docs/                          ARCHITECTURE_PLAN.md, DATABASE_DESIGN.md, DECISIONS.md, PERMISSIONS.md, GoLive_Checklist.md,
│                                  DEPLOYMENT.md, OPERATIONS.md, Static_Analysis_Known_False_Positives.md; not built: MODULES.md, DESIGN_TOKENS.md
├── lang/{ar,en}/                  app, navigation, enums, auth, passwords, validation, pagination, dashboard, leads, contacts, accounts, deals,
│                                  pipelines, activities, tasks, notes, attachments, calendar, tags, custom_fields, users, roles, teams, settings,
│                                  views, imports, reports, activity, notifications
├── lang/vendor/                   Filament Arabic gap patches
├── resources/css/                 filament/admin/theme.css (the panel is the whole front end; no non-panel stylesheet)
├── resources/js/                  calendar.js
├── resources/views/filament/      pages/, widgets/, components/timeline/, activity-log/
├── routes/                        web.php, console.php
├── tests/                         Concerns/, Support/, Feature/{Access,Audit,Leads,Contacts,Accounts,Deals,Activities,Tasks,Attachments,Imports,
│                                  Views,Search,Filament,Isolation,Qa,Seeders,System,Domain,Statistics,Localization}, Unit/{Services,Deployment}
├── tools/                         ramp.mjs (colour ramps)
├── .github/workflows/             ci.yml (lint, analyse, test on push/PR); deploy.yml (manual release archive, optional SSH deploy)
├── CLAUDE.md  README.md  CONTRIBUTING.md
└── composer.json  package.json  vite.config.js  phpstan.neon.dist  phpunit.xml  .editorconfig  .gitattributes  .env.example  .gitignore
```

---

## 11. Risks

| Risk | Mitigation |
|---|---|
| Production DB engine differs from MySQL 8 (Stockflow's host runs MariaDB) | D-1: engine confirmed in writing before the first production migration; `DatabaseEngine` / `EnumCheck` engine-aware; migrations proven on MariaDB 10.4.32 and the full suite showed no engine difference (A-21) — recheck on the host's exact version |
| Shared hosting queue latency (≤ 60 s) | Documented; reminders/notifications tolerate it; imports chunked |
| Git Bash resolves the wrong PHP | All scripts through Herd `composer`; README warning; CI on PHP 8.3 |
| Filament Arabic translation gaps | `lang/vendor` patches + vendor fallback test |
| Custom kanban/calendar RTL and dark-mode fidelity | Server-provided direction/labels; visual tests in both modes; custom pages per D-12 |
| Permission drift if roles are runtime-editable | Drift test pins seeded defaults; runtime edits audited; `super_admin` cannot be removed from the last super admin |
| Scope creep beyond v1 | Out-of-scope list in §2 enforced; new external services need a decision |

---

## 12. Decision register

The decision register lives in one place: [DECISIONS.md](DECISIONS.md) — owner decisions D-1 … D-13 and architect
decisions A-1 … A-21, with amendments recorded in the row they change. This plan no longer keeps its own copy, which
had drifted from the register (its A1 … A15 numbering did not match A-1 … A-15).
