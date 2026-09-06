# CRM — Architecture and Implementation Plan

Status: **Plan approved 2026-09-05. All 13 owner questions answered — see [DECISIONS.md](DECISIONS.md) (D-1 … D-13). Steps 0–6 (scaffold, foundation, lookups, accounts & contacts, leads, deals & pipelines, lead conversion) complete 2026-09-06; step 7 (activities, notes, attachments, tasks, timeline, calendar) complete 2026-09-06; step 9 (search, query-builder filters, saved views, import/export) complete 2026-09-07; step 10 (dashboard and reports) complete 2026-09-07; step 11 (custom fields) complete 2026-09-07; step 12 (quality pass) and step 13 (production readiness) remain.**

Companion documents:

| Document | Purpose |
|---|---|
| [DATABASE_DESIGN.md](DATABASE_DESIGN.md) | Every table, column, key, index and constraint |
| [STOCKFLOW_COMPARISON.md](STOCKFLOW_COMPARISON.md) | Stockflow vs CRM comparison table and the reuse classification |
| [DECISIONS.md](DECISIONS.md) | Owner decisions D-1 … D-13 and architect decisions A-1 … A-15 |
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
| 17 | Filtering & saved views | Advanced filters (incl. query builder), per-user saved views with optional sharing, persisted table state |
| 18 | Import / Export | CSV import with column mapping, validation, duplicate handling, failed-rows report, history; CSV/XLSX export of tables and reports; permission-gated |
| 19 | Notifications | In-app bell (database notifications): assignment, task reminders, overdue, deal stage changes, mentions, import/export completion; mail channel only when a mailer is configured and the user opted in |
| 20 | Roles & permissions | spatie roles/permissions, six default roles, permission keys per verb (view/create/update/delete/export/import/assign/convert/change stage/manage settings/reports/audit/admin), policies enforced server-side |
| 21 | Teams & ownership | Teams, record owner, assignment with history, visibility own/team/all driven by permissions (D-4); reps cannot reassign |
| 22 | Audit logs | spatie activitylog ledger: create/update/delete diffs, ownership, status/stage, permission and settings changes, login events; read-only UI; retention policy |
| 23 | Reports | Lead, conversion/funnel, pipeline, sales performance, activity, source performance, win/loss, forecast, task performance; export from every report |
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
  `users.manage`, `roles.manage`, `saved_view.share`).
- Default role→permission sets live in `App\Support\Access\RolePermissionMatrix` and are seeded by
  `RolesAndPermissionsSeeder` (idempotent, `syncPermissions`, cache flushed). A drift test asserts
  the seeded tables equal the matrix; runtime edits through the Roles resource are audited (D-3).
- `super_admin` is granted through `Gate::before`. Roles: `super_admin`, `admin`, `sales_manager`,
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

- Laravel notifications with Filament's database envelope (`Notification::make()->…->getDatabaseMessage()`),
  panel `->databaseNotifications()->databaseNotificationsPolling('30s')`, actions inside notifications
  (open record, mark read).
- Events: `LeadAssigned`, `DealAssigned`, `TaskAssigned`, `TaskDue`, `TaskOverdue`, `DealStageChanged`,
  `DealWon/Lost`, `LeadConverted`, `MentionedInNote`, `ImportCompleted`/`ExportCompleted` (Filament).
- Channels: `database` always; `mail` only when `config('mail.default')` is a real transport and the
  user's `notification_preferences` row enables it. No paid provider.
- Queue: `QUEUE_CONNECTION=database`, drained by the scheduler every minute
  (`queue:work --stop-when-empty --max-time=50`, `withoutOverlapping(10)`, `onOneServer()`), `sync` in
  tests. Fixed by D-1 (shared hosting, no persistent worker).
- Scheduler (`routes/console.php`): queue drain, `tasks:remind` (every 5 minutes, idempotent via
  `reminder_sent_at`), `tasks:flag-overdue`/`leads:flag-stale` (daily), `activitylog:clean`,
  `queue:prune-failed`, `model:prune` (imports/exports).

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

- Filament global search on Lead, Contact, Account, Deal, Task: `getGloballySearchableAttributes()`
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
   ├─► Search, saved views, import/export (need the entities and visibility resolver)
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
| 9 | **Search, saved views, import/export**: global search config, saved views, Filament importers/exporters, import/export history resources, prune schedule | Scope tests, importer tests with fixture CSVs |
| 10 | **Dashboard & reports**: statistics services, widgets, charts, nine report pages with export | Report query tests against seeded data, permission tests |
| 11 | **Custom fields** (D-9, all four entities): definitions, typed values, dynamic form/table/filter components, import/export columns | Tests per field type |
| 12 | **Quality pass**: security, performance (N+1 with `preventLazyLoading` in tests, indexes, query counts), RTL/LTR walk, dark/light walk, translation audit, DB review, authorisation matrix walk, test review | Checklist in `docs/GoLive_Checklist.md` complete |
| 13 | **Production readiness**: clean install from scratch, migrations fresh + seed, CI green, `filament:optimize`, deployment runbook, `.env` documentation, demo data command | Acceptance criteria in the master prompt §24 |

---

## 6. Testing strategy

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
| Authorization | Policies for every model incl. lookups and settings; permission keys enumerated; `Gate::before` only for `super_admin`; `->strictAuthorization()`; bulk `authorizeIndividualRecords`; visibility resolver in every query path; importers/exporters/widgets/pages gated |
| Record ownership | `owner_id` on leads/contacts/accounts/deals/tasks; assignment permission; ownership changes audited and notified |
| Mass assignment | `#[Fillable]` whitelists; workflow columns guarded by observers; `Model::shouldBeStrict()` outside production |
| Validation | Filament schema rules + `Rule::unique` closures + custom rules for phone/email normalisation; server-side only |
| CSRF / sessions | Filament middleware stack (`PreventRequestForgery`, `AuthenticateSession`); `SESSION_SECURE_COOKIE=true` in production (preflight fails otherwise) |
| Files | Private disk, server-side MIME sniffing allowlist, size caps from config, UUID names, per-entity directories, authorised download route, `FileUpload::preventFilePathTampering()` |
| XSS | Blade escaping; `RichEditor` JSON content rendered through Filament's sanitising renderer; `Str::sanitizeHtml` on any HTML output |
| SQL injection | Eloquent/bindings only; raw aggregates parameterised |
| Sensitive data | No secrets in the ledger; `#[Hidden]`; encrypted casts for any future integration credentials; no IDs/credentials in notifications |
| Rate limiting | `throttle` on every public POST (password reset), Filament login limiter, `rateLimit()` on heavy actions (import, export) |
| Audit | Ledger per §3.7 |
| Errors | `APP_DEBUG=false` enforced by preflight; friendly translated error pages |
| Headers | Security headers middleware (nosniff, frame-deny, referrer policy, HSTS when https) |

---

## 8. Performance plan

- Eager loading declared per table (`modifyQueryUsing(fn ($q) => $q->with([...]))`), `preventLazyLoading`
  in tests so N+1s fail the suite.
- Indexes per [DATABASE_DESIGN.md](DATABASE_DESIGN.md); dashboard/report queries are aggregate SQL, not
  collection loops; optional `Cache::remember` (file cache) for dashboard KPIs with short TTL.
- Pagination everywhere; kanban limits cards per column with "load more"; calendar loads by visible range.
- Imports chunked (100 rows) and queued; exports chunked and queued; both prunable.
- Global search limited per resource; indexed columns only.
- `filament:optimize`, config/route/view cache in deploys.

---

## 9. Deployment readiness

- Target host is Hostinger shared hosting (D-1): GitHub Actions builds assets
  and ships `public/build`, deploys over SSH with `scripts/deploy-production.sh` (maintenance mode,
  `composer install --no-dev`, `migrate --force`, caches, `filament:optimize`, `app:preflight`, `up`),
  one cron running `schedule:run` every minute, storage symlink created once by hand, `.env` set on the
  server only. If a VPS: same pipeline plus Supervisor for `queue:work`.
- `app:preflight` fails on `APP_DEBUG=true`, blank `APP_KEY`, `APP_ENV≠production`, non-https `APP_URL`,
  `SESSION_SECURE_COOKIE≠true`, `log` mailer, `sync` queue.
- Documentation set: README, CLAUDE.md, CONTRIBUTING.md, `docs/ARCHITECTURE.md`, `DATABASE.md`,
  `DECISIONS.md` (+ ADRs), `PERMISSIONS.md`, `MODULES.md`, `DEPLOYMENT.md`, `GoLive_Checklist.md`,
  `Static_Analysis_Known_False_Positives.md`, `DESIGN_TOKENS.md`.

---

## 10. Proposed folder structure

```
CRM_Project/
├── app/
│   ├── Console/Commands/          OnboardCommand (app:onboard), PreflightCommand (app:preflight), SeedDemoCommand (app:seed-demo),
│   │                              RemindTasksCommand (tasks:remind), FlagOverdueTasksCommand (tasks:flag-overdue), FlagStaleLeadsCommand (leads:flag-stale)
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
│   │   │                          Competitors/, Tags/, Teams/, CustomFields/, Users/, Roles/, SavedViews/, ActivityLogs/, Imports/, Exports/
│   │   │                          (each: XResource, Schemas/, Tables/, Pages/, RelationManagers/)
│   │   ├── Support/               LeadActions, DealActions, TaskActions, ContactActions, SharedSchemas (address, owner, tags, custom fields)
│   │   └── Widgets/               SalesKpisWidget, LeadFunnelWidget, PipelineByStageChart, WonRevenueTrendChart, MyTasksTodayWidget,
│   │                              OverdueTasksWidget, UpcomingFollowUpsWidget, StaleDealsWidget, RecentLeadsWidget, ActivityFeedWidget
│   ├── Http/Controllers/          AttachmentDownloadController
│   ├── Jobs/                      SendTaskReminder, SendAssignmentNotification
│   ├── Listeners/                 ActivateInvitedUser, RecordAuthActivity, PersistUserLocale, RecordLastLogin
│   ├── Models/
│   │   ├── Concerns/              HasLocalisedName, HasOwner, HasTags, HasAttachments, HasNotes, HasTasks, HasActivities, HasCustomFieldValues, GuardsWorkflowFields
│   │   └── *.php                  User, Team, Setting, Lead, LeadStatus, LeadStatusLog, LeadSource, Industry, Account, Contact, Deal, DealStageLog,
│   │                              DealContact, DealCloseReason, Competitor, Product, DealProduct, Pipeline, PipelineStage, ActivityType,
│   │                              Activity, Task, Note, Attachment, Tag, CustomField, CustomFieldValue, LeadScoringRule, EmailTemplate, SavedView, NotificationPreference, ActivityLog
│   ├── Notifications/             LeadAssignedNotification, DealAssignedNotification, TaskAssignedNotification, TaskDueNotification,
│   │                              TaskOverdueNotification, DealStageChangedNotification, DealClosedNotification, LeadConvertedNotification,
│   │                              MentionedInNoteNotification, UserInvitationNotification
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
│   │   ├── Users/                 UserInvitationService
│   │   └── Views/                 SavedViewStore
│   └── Support/
│       ├── Access/                RolePermissionMatrix
│       ├── Database/              EnumCheck
│       ├── Filament/              FilamentLanguageMenuItems
│       ├── Money.php              deal line totals (D-8)
│       └── PhoneNumber.php        normalisation
├── bootstrap/                     app.php, providers.php
├── config/                        admin.php, brand-colors.php (generated), crm.php, activitylog.php, permission.php, filament.php
├── database/                      migrations/ (one table per file), seeders/ (DatabaseSeeder, RolesAndPermissionsSeeder, LookupSeeder,
│                                  DefaultPipelineSeeder, DemoDataSeeder), factories/
├── docs/                          ARCHITECTURE.md, DATABASE.md, DECISIONS.md, decisions/ADR-*.md, PERMISSIONS.md, MODULES.md, DEPLOYMENT.md,
│                                  GoLive_Checklist.md, Static_Analysis_Known_False_Positives.md, DESIGN_TOKENS.md
├── lang/{ar,en}/                  app, navigation, enums, auth, passwords, validation, pagination, dashboard, leads, contacts, accounts, deals,
│                                  pipelines, activities, tasks, notes, attachments, calendar, tags, custom_fields, users, roles, teams, settings,
│                                  views, imports, reports, activity, notifications
├── lang/vendor/                   Filament Arabic gap patches
├── resources/css/                 app.css, filament/admin/theme.css
├── resources/js/                  app.js, deal-board.js, calendar.js
├── resources/views/filament/      pages/, widgets/, components/timeline/, activity-log/
├── routes/                        web.php, console.php
├── scripts/                       deploy-production.sh
├── tests/                         Concerns/, Support/, Feature/{Access,Audit,Leads,Contacts,Accounts,Deals,Activities,Tasks,Attachments,Imports,
│                                  Views,Search,Filament,Isolation,Qa,Seeders,System,Domain,Statistics,Localization}, Unit/{Services,Deployment}
├── tools/                         ramp.mjs (colour ramps)
├── .github/workflows/             ci.yml (lint, analyse, test on push/PR), deploy.yml (master → host)
├── CLAUDE.md  README.md  CONTRIBUTING.md
└── composer.json  package.json  vite.config.js  phpstan.neon.dist  phpunit.xml  .editorconfig  .gitattributes  .env.example  .gitignore
```

---

## 11. Risks

| Risk | Mitigation |
|---|---|
| Production DB engine differs from MySQL 8 (Stockflow's host runs MariaDB) | D-1: engine confirmed before the first production migration; `EnumCheck` engine-aware; no MySQL-only JSON functions |
| Shared hosting queue latency (≤ 60 s) | Documented; reminders/notifications tolerate it; imports chunked |
| Git Bash resolves the wrong PHP | All scripts through Herd `composer`; README warning; CI on PHP 8.3 |
| Filament Arabic translation gaps | `lang/vendor` patches + vendor fallback test |
| Custom kanban/calendar RTL and dark-mode fidelity | Server-provided direction/labels; visual tests in both modes; custom pages per D-12 |
| Permission drift if roles are runtime-editable | Drift test pins seeded defaults; runtime edits audited; `super_admin` cannot be removed from the last super admin |
| Scope creep beyond v1 | Out-of-scope list in §2 enforced; new external services need a decision |

---

## 12. Decision register

### 12.1 Decided by the architect (implementation details within the established standard)

| # | Decision |
|---|---|
| A1 | Stack pinned to the Stockflow line: Laravel `^13.0`, Filament `^5.0`, PHP `^8.3` (8.4 locally), MySQL 8.4, spatie permission `^8`, activitylog `^4.12`, language-switch `^5`, PHPUnit `^12`, Larastan `^3`, Pint `^1`, Vite 8, Tailwind 4 |
| A2 | Single Filament panel at `/admin`; no multi-panel layer, no launcher, no platform tier |
| A3 | Resource composition and naming exactly as Stockflow (Schemas/Tables/Pages/RelationManagers), `final` + `strict_types`, attribute-based model config |
| A4 | Configurable business data as bilingual lookup rows; code enums only for behaviour; `EnumCheck` DB constraints on code-enum columns |
| A5 | spatie/laravel-permission as the permission store with typed `Permission` keys, seeded from a code matrix with a drift test; own bilingual Roles resource (D-3) |
| A6 | spatie/laravel-activitylog as the audit ledger with append-only observer, separate `deal_stage_logs`/`lead_status_logs` history tables, and a composed timeline reader |
| A7 | Own `attachments` table on a private disk with an authorised download route (no media-library package) |
| A8 | Filament built-in Import/Export actions with importers/exporters, private disk, prunable history resources |
| A9 | Database queue drained by the scheduler (Stockflow's production pattern; D-1) |
| A10 | Filament global search + own `saved_views` table + persisted table state |
| A11 | One Tailwind 4 theme file with CRM tokens and a generated colour ramp; light/dark/system via Filament |
| A12 | Testing: PHPUnit 12 on MySQL; PHPStan level 5 with `checkModelProperties`, no baseline; Pint default; CI runs lint + analyse + test |
| A13 | Notes are a mutable table with edit history in the audit ledger; activities are immutable events; tasks are mutable |
| A14 | Duplicate detection = normalised email/phone exact match (warn, not block) + a merge action; no fuzzy matching in v1 |
| A15 | Documentation and git conventions mirror Stockflow (README, CLAUDE.md, docs/, conventional commits, `master`/`develop` branches) with a written CONTRIBUTING.md |

### 12.2 Owner decisions

All thirteen questions were answered on 2026-09-05 — see [DECISIONS.md](DECISIONS.md) D-1 … D-13.
