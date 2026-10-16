# CRM — Project Constitution

Bilingual (ar + en), RTL + LTR, light/dark CRM on **Laravel 13 + Filament 5 + MySQL 8**, single organisation,
a home panel plus four workspace panels (D-23). Engineering standard inherited from the Stockflow (ZonKSA)
project and adapted; the authoritative plan is `docs/ARCHITECTURE_PLAN.md`, the schema `docs/DATABASE_DESIGN.md`, the decisions
`docs/DECISIONS.md` (D-1 … D-28 owner, A-1 … A-26 architect). **Every implementation is production-ready or it
is not delivered**: no TODOs, no placeholders, no dummy data, no half-wired buttons.

## 1. Frozen stack

PHP `^8.3` (Herd 8.4 locally, 8.3 in CI — no PHP 8.4-only syntax), Laravel `^13.0`, Filament `^5.0`,
Livewire 4, MySQL 8.4 (tests too — never SQLite), spatie/laravel-permission `^8`, spatie/laravel-activitylog
`^4.12`, bezhansalleh/filament-language-switch `^5`, PHPUnit `^12` (attributes; no Pest), Larastan `^3`
level 5 with `checkModelProperties`, Laravel Pint (default preset), Vite 8 + Tailwind 4 (CSS-first; no
`tailwind.config.js`). Do not add packages without a decision in `docs/DECISIONS.md`.

## 2. Layering (no exceptions)

Filament Resources / Pages / Widgets → **present only** (gate + call a service).
Policies → **authorise** (permission + record scope).
Services (`app/Services/<Domain>`) → **every business rule**, inside `DB::transaction()` where state changes.
Models + Observers → integrity guards. No business logic in Filament classes, controllers or models.

## 3. Conventions

- `declare(strict_types=1);` and `final class` on every project class (models, services, enums, policies,
  resources, pages, tests, migrations, seeders). Guard test enforces it.
- Models: `#[Fillable([...])]`, `#[Hidden([...])]`, `#[ObservedBy(...)]` attributes; `protected function casts(): array`;
  a `@property` docblock for **every** enum/date/decimal cast (Larastan reads the DB, not `casts()`);
  typed relation generics (`@return BelongsTo<Account, $this>`); never `$hidden`/`$fillable` properties.
- Enums: `App\Enums\*`, backed by strings, `label()` from `lang/*/enums.php` only, implement Filament
  `HasLabel` (+ `HasColor`/`HasIcon` when shown as badges). Code enums only for behaviour; configurable
  business data (statuses, sources, stages, types, reasons, tags, teams) are **rows** with `name_ar`/`name_en`.
- Migrations: **one table per file**, docblock naming purpose + decision, explicit FK actions
  (`restrictOnDelete()` default, `cascadeOnDelete()` for owned children, `nullOnDelete()` for user refs),
  sized strings, indexes on every FK/`owner_id`/status column, `softDeletes()` on business entities only,
  code-enum columns `string(32)` + `App\Support\Database\EnumCheck::apply()`, reversible `down()`.
- Filament resources: `XResource` + `Schemas/XForm`, `Schemas/XInfolist`, `Tables/XsTable`,
  `Pages/{List,Create,View,Edit}X`, `RelationManagers/*`. Labels only through `getNavigationLabel()`,
  `getModelLabel()`, `getPluralModelLabel()`; `getNavigationGroup()` returns an `App\Enums\NavigationGroup`
  case. Every Section/Schema declares its column map **explicitly** (A-22): `->columns(['default' => 1, 'lg' => 2])`
  where the section holds short fields that pair, `->columns(1)` for single-field/long-content sections
  (a lone textarea, a repeater, a grid that manages its own density) — never an implicit default; a
  full-width field inside a 2-column section says `->columnSpanFull()` (textareas, repeaters, warnings,
  the custom-fields injection); the top-level Schema stays `->columns(1)` so sections stack.
  Tables: `->defaultSort()`, `->recordActions()`,
  `->toolbarActions([BulkActionGroup::make([...->authorizeIndividualRecords(...)])])`, persisted
  filters/sort/search, translated empty states. `getEloquentQuery()` applies `ScopesQueriesToVisibleRecords`.
- Panels (D-23): a home panel (`/app`: sign-in, password reset, profile + MFA, notification preferences, the
  workspace screen) and four workspaces (`App\Enums\WorkspacePanel`: Sales `/sales`, Tasks `/tasks`, Reports
  `/reports`, Administration `/admin`). Every resource, page and widget is listed in exactly one panel in
  `App\Support\Filament\WorkspacePanelRegistry` (no discovery) and uses `BuildsUrlsInOwningPanel` /
  `BuildsPageUrlsInOwningPanel`, so `getUrl()` points into its owning panel from anywhere (jobs, mails, other
  panels). Shared panel configuration lives only in `Providers\Filament\Concerns\ConfiguresCrmPanel`. Entry is
  `Services\Access\PanelAccess` (`workspace.*` keys; `User::canAccessPanel()` delegates; fail closed). A link into
  another workspace goes through `Support\Filament\PanelUrl::forReader()` and renders as plain text when it returns
  null — links never dead-end. A moved `/admin/...` address gets a row in `Support\Http\LegacyAdminRedirects`.
  Non-panel routes that need the panel's look or auth carry `panel:app`.
- Tasks workspace (D-27): screens draw a task only through the shared presentation helpers —
  `Filament\Support\TaskVisuals` (status pill, priority flag, avatar, checklist meter, relative due, completion check;
  columns and entries) or the `<x-task.*>` Blade components — on the organisation clock
  (`Services\Settings\OrganisationCalendar`, `RelativeDueDates`; never the server day, never SQL `DATE()`). Every new
  task control is a `TaskActions` action (below its D-27 marker) authorised by the `TaskPolicy` verb and writing only
  through `TaskService`; a page without a table record resolves the task from the reader's readable query. The Sales
  task tabs use `TasksTable::configureForSubjectTab()` / `TaskInfolist::configureForSubjectTab()` and stay as they are.
- Authorisation: policy per model using `Policies\Concerns\ChecksPermissions` (explicit `deleteAny`,
  `restoreAny`, `forceDeleteAny`; `forceDelete` = false); `App\Enums\Permission` keys; record scope through
  `Services\Access\RecordVisibilityResolver`; every custom action `->authorize()`d; every bulk action
  `->authorizeIndividualRecords()`; widgets `canView()`; pages `canAccess()`. Never rely on hidden UI.
- Localisation: **no user-facing string in code, in any language.** Everything via `__('module.group.key')`
  from `lang/ar/<module>.php` and `lang/en/<module>.php` with identical keys (parity test). Fixed sub-keys:
  `navigation`, `sections`, `fields`, `placeholders`, `helpers`, `validation`, `filters`, `actions`,
  `confirmations`, `notifications`, `empty`, `pages`. Arabic default, English fallback (D-5).
  Arabic glossary: pipeline «مسار المبيعات» (plural «مسارات المبيعات»), deal «صفقة», owner «المسؤول», administrator
  «مدير النظام», prospect account «عميل مرتقب», won/lost «مكسوبة/مفقودة»; quotes « », digits 0-9, tanween written «اً»;
  counted nouns use `trans_choice` (Arabic `{0}/{1}/{2}/[3,10]/[11,99]/[100,*]`, English `{1}/[2,*]`); Arabic text
  uses «من … إلى …», never arrows between values; audit labels for one model go in
  `activity.subject_attributes.<Model>.<key>`.
- Theme: `resources/css/filament/admin/theme.css` only (all five panels and the landing page share it); tokens `--crm-*` in `:root` and `.dark`; Filament `--gray-*`
  overrides must be full colour values (e.g. `var(--crm-*)`), never bare RGB triplets; logical CSS
  properties; no `[dir='ltr']` exceptions; Latin-only values wrapped `dir="ltr"`. Motion («روح», A-9): every
  animation/transition sits behind `@media (prefers-reduced-motion: no-preference)`, 140–450 ms, translateY/scale
  transforms only; the KPI count-up is `resources/views/filament/motion.blade.php` (BODY_END render hook), animates
  whole numbers only and always ends on the server-rendered text.
- Audit: models with business meaning use `LogsActivity` with an explicit `logOnly` whitelist; service events go
  through `Services\Audit\<Domain>ActivityLogger` with an `ActivityLogEvent` case. Secrets never logged.
- Notifications: Laravel notification classes wrapping Filament's database envelope; `via()` adds `mail` only
  when a real transport is configured and the user's preference for the event allows it — mail is opt-in by
  default for every event except `RecordAssigned` (D-14), `TaskMention` (D-20), `WeeklySummary` (D-18) and
  `BackupFailed` (D-16), which are opt-out (`NotificationEvent::mailByDefault()`).
- Tests: `#[Test]` attribute, snake_case sentence names, `RefreshDatabase` on `crm_testing`,
  `$this->usePanel()` in `setUp()` before `Livewire::test` (it makes the home panel current and switches to each
  mounted component's owning panel; code that reads the current panel directly — `getNavigation()`, panel walks —
  sets it explicitly), QualityPass walkers walk every panel (`Filament::getPanels()`), multi-role HTTP walks use a
  DataProvider per role (the current panel is a scoped binding that leaks across requests), fixtures from
  `Tests\Concerns\CreatesCrmFixtures`
  (upload bytes from `Tests\Concerns\BuildsUploadBytes`; never copy a helper into a test class), lazy loading prevented
  for the whole suite,
  per-role authorisation matrices, negative visibility tests for every owned entity.
  `tests/Feature/QualityPass/*` are permanent regression tests from the step-12 quality pass: never weaken or delete one.

## 4. Commands

```bash
composer check          # pint --test, phpstan, phpunit — must be green before every commit
composer lint           # vendor/bin/pint --test
composer analyse        # vendor/bin/phpstan analyse --memory-limit=1G
composer test           # php artisan test (MySQL crm_testing)
php artisan app:onboard # first super admin
php artisan app:preflight
```

Always run through **Herd's PHP/Composer** (PowerShell resolves them; Git Bash resolves XAMPP 8.2 — do not use it).

**A push to `master` is a production release (A-25):** once CI passes, the Deploy workflow ships that exact commit to
the live site automatically. Push to `master` only work whose `composer check` is green.

## 5. Decisions in force (summary — full text in docs/DECISIONS.md)

Hostinger shared hosting (D-1) · single organisation, no tenant column (D-2) · spatie tables are the runtime
permission authority, own Roles resource, no Shield (D-3) · permission-driven visibility own/team/all with
teams; reps cannot reassign (D-4) · Arabic default (D-5) · one `accounts` entity, customer on first won deal,
one account per contact (D-6) · lead statuses New/Contacted/Qualified/Converted/Unqualified, qualification
note required, rule-based scoring with override, convert only from Qualified (D-7) · products + deal line
items, SAR, Asia/Riyadh, forecast categories, stage probability with override (D-8) · typed custom fields on
Lead/Contact/Account/Deal (D-9) · templated email via app mailer, logged as activities, log driver locally
(D-10) · optional MFA, 12-char passwords, 120-min sessions, invite-only (D-11) · custom kanban and calendar
pages (D-12) · audit 730 days, soft deletes kept, reps export within scope (D-13) · admin-only task
assignment, assignment mail default-on, completion notice to the assigner, shared tasks board open to every
role (D-14) · Employee role for task-receiving staff (D-15) · weekly self-backup and uptime monitor (D-16) ·
task comments (D-17) · weekly summary e-mail (D-18) · preferences show only receivable events (D-19) ·
@mentions in task comments pull a colleague into the task: read, files and comments, never edit (D-20) · the task
report shows each employee's average time to finish, from `tasks.assigned_at` (D-21) · routine tasks: weekly or
monthly templates, `task.assign` only, one task per assignee handed out hourly by `tasks:generate-routines` (D-22) ·
workspaces: home panel `/app` + Sales, Tasks, Reports and Administration panels entered by `workspace.*` keys, a
workspace screen with locked cards after sign-in, owning-panel links shown only to readers who may enter, permanent
redirects from the old `/admin/...` addresses (D-23) · a public bilingual landing page at `/`, truthful claims only,
panels kept out of search engines (D-24) · task checklists: written with `update`, ticked with `progress` while
open, completion with unticked items allowed with a warning, copied unticked by routines and recurrence (D-25) ·
an employee dashboard in Reports (`/reports/employee`): one employee's task work, reach by the reader's task visibility
all/team/own, a task's thread text and link only where the reader may open the task, read-only (D-26) · a ClickUp-style
Tasks workspace: My Work home in buckets, one visual language (status, flag, avatar, meter, relative dates on the
organisation day), one-click completion check with undo, status pill with only the allowed moves, quick add, list grouped
by status, two-column task page with one-click checklist and an always-visible composer, board by status with drag, Inbox
page, phone tab bar, installable web app without service worker, keys N, / and ?; the Sales task tabs unchanged (D-27) ·
shared tasks: several assignees on one task (one status, checklist and thread), the first in `tasks.assignee_id`, the
others in `task_co_assignees`; `task.assign` holders share within their reach; every assignee owns it for visibility and
works on it; "a user's tasks" is `Task::assignedTo()`; reports count it per assignee (D-28).

## 6. QA checklist before any commit

Architecture layering respected · schema integrity (FKs, indexes, CHECKs) · policy + resolver on every path ·
both locales, both directions, both themes checked · no hardcoded strings · tests written and green ·
PHPStan clean (or false positive documented in `docs/Static_Analysis_Known_False_Positives.md`) · Pint clean ·
docs updated when a decision or schema changes.
