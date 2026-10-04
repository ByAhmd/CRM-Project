# CRM

Bilingual (Arabic + English, RTL + LTR), light/dark, production-grade CRM built on **Laravel 13 + Filament 5 + MySQL 8**,
following the engineering standards established by the Stockflow (ZonKSA) project.

## Status

| Phase | State |
|---|---|
| 1 — Discovery (CRM project, Stockflow reference, environment) | ✅ done 2026-09-04 |
| 2 — Architecture plan | ✅ delivered 2026-09-04 — see `docs/` |
| 3 — Critical questions | ✅ answered 2026-09-05 — [docs/DECISIONS.md](docs/DECISIONS.md) |
| 4 — Foundation (scaffold, auth, roles, lang, theme, audit, tests) | ✅ done 2026-09-05 |
| 5 — Modules, plan steps 2–10 (lookups → accounts/contacts → leads → deals → conversion → activities → notifications → search/import → dashboard/reports) | ✅ done 2026-09-07 — lookups, accounts & contacts, leads, deals & pipelines (stage workflow, line items, kanban), lead conversion, activities / notes / attachments / tasks, timeline and calendar, notifications and templated email, search / filters / import-export, dashboard and nine reports (saved views were part of this step and were removed on 2026-09-17 as unused — A-8 amendment in [docs/DECISIONS.md](docs/DECISIONS.md)) |
| Step 11 — Custom fields (D-9) | ✅ done 2026-09-07 — typed custom fields on leads, contacts, accounts and deals: forms, tables, filters, import and export |
| Step 12 — Quality pass (security, performance, localisation and RTL, theme, schema on MySQL 8.4 and MariaDB 10.4, authorisation matrix, test review) | ✅ done 2026-09-15 — steps 0–12 complete; open go-live items with their owners in [docs/GoLive_Checklist.md](docs/GoLive_Checklist.md) |
| Step 13 — Production readiness (deployment pipeline and runbook, trusted proxies, clean install, demo data) | ✅ engineering done 2026-09-15 — deploy workflow, [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md), [docs/OPERATIONS.md](docs/OPERATIONS.md), production preflight, trusted proxies, scheduler heartbeat, `app:demo-data`; clean install, rollback and the whole suite proven on MySQL 8.4 and MariaDB 10.4; real database-queue import/export smoke run; EXPLAIN at 40× volume. Go-live rows that need the host or the owner stay open in [docs/GoLive_Checklist.md](docs/GoLive_Checklist.md) |
| Owner additions after launch (D-14 … D-22) | ✅ 2026-09-21 … 2026-10-04 — see *Features* below |

## Features

- **Sales:** leads (status workflow with a required qualification note, rule-based scoring with override, conversion
  from Qualified only), accounts and contacts (duplicate detection and merge), deals on pipelines with a kanban board,
  product line items, forecast categories and won/lost reasons (D-6 … D-8); typed custom fields on all four (D-9).
- **Work:** activities, notes, attachments, a timeline on every record, tasks with reminders, overdue notices and
  per-task repeat, a calendar (D-12) and a shared tasks board (D-14). Tasks are handed out by administrators; the
  assignee starts, reports progress on and completes them, and the assigner is told (D-14). An **Employee** role
  receives and reports on tasks without seeing the sales pipeline (D-15).
- **Task threads:** comments on any task one may open (D-17); **@mentions** in a comment pull a colleague into the
  task — they may read it, its files and comments and are told at once, but never edit it (D-20).
- **Routine tasks:** weekly or monthly templates set up once by the people who hand out work; on each scheduled day
  every assignee gets a task of their own, announced like any assignment (D-22, `tasks:generate-routines`, hourly).
- **Reports:** a dashboard and nine reports with export, including a per-employee task report — completed, on time,
  late, open, overdue, completion rate and **average time to finish** (D-21).
- **Notifications:** in-app bell and opt-in e-mail per event, with a preferences page that shows only what each user
  can receive (D-19); a weekly summary e-mail to the people who hand out work (D-18); templated e-mail to contacts (D-10).
- **Administration:** roles and permissions with own/team/all record scope (D-3, D-4), invite-only users with optional
  MFA (D-11), an append-only audit log kept 730 days (D-13), imports and exports within scope, a weekly self-backup
  with download and an external uptime-and-scheduler monitor (D-16).
- **Everywhere:** Arabic (default, right to left) and English, light and dark themes.

## Local setup

```bash
composer install            # through Laravel Herd's PHP 8.4
npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan app:onboard     # seeds all reference data (roles, permissions, settings, lookups, default pipeline,
                            # system activity types, email templates), then creates the first super admin
php artisan app:preflight   # fails on pending migrations or missing reference data
herd link crm               # http://crm.test/admin
php artisan view:cache      # REQUIRED, and again after every `optimize:clear` — see "Compiled views" below
php artisan app:demo-data   # optional: bilingual demo dataset through the real services (9 demo users, one password
                            # printed once); never in production; remove it with `php artisan app:demo-data --fresh`
```

The reference seed is idempotent and never overwrites an administrator's edits: `php artisan db:seed --force` after a
deploy only recreates missing rows.

### Compiled views

`php artisan view:cache` is part of the setup, not an optimisation. Without it Blade compiles the whole Filament view
tree on every request, and a panel page issues several requests at once (the page itself, each lazy widget, the
notification poll). On Windows two of them renaming the same compiled file at the same moment fails with
`rename(...\storage\framework\views\xxx.tmp, ...php): Access is denied (code: 5)` and the page 500s. Always run it
after anything that empties the cache:

```bash
php artisan optimize:clear && php artisan view:cache
```

Then load one page. That first request is part of the warm-up: it materialises the handful of templates Blade
generates at runtime (inline component strings), which no warm-up command can precompile. After it the cache is
complete and concurrent requests never compile anything.

In production `php artisan optimize` already does this (see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)), and Linux is
not affected by the rename failure — but an uncached view tree is a large, needless cost there too.

Databases `crm` and `crm_testing` must exist on the local MySQL 8.4 (user `crm` / `crm`). Tests run on
`crm_testing` and rebuild it on every run:

```bash
composer check              # pint --test, phpstan, phpunit — must be green before every commit
```

## Documents

| Document | Purpose |
|---|---|
| [docs/ARCHITECTURE_PLAN.md](docs/ARCHITECTURE_PLAN.md) | Discovery findings, product scope, system architecture, module dependencies, implementation order, testing, security, performance, deployment, folder structure, risks, decision register |
| [docs/DATABASE_DESIGN.md](docs/DATABASE_DESIGN.md) | Every table, column, key, index and integrity rule, as built |
| [docs/STOCKFLOW_COMPARISON.md](docs/STOCKFLOW_COMPARISON.md) | Stockflow vs CRM comparison table, reuse classification (must / should / CRM-specific / must not), conflict resolution |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Owner decisions D-1 … D-22 and architect decisions A-1 … A-26 |
| [docs/PERMISSIONS.md](docs/PERMISSIONS.md) | Seeded roles and their default grants, record scope (own / team / all) and the guards above the permissions |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Hostinger runbook: prerequisites, first deployment, the cron line and what it drives, releases, rollback, PHP limits, backups, logs, monitoring, the deploy workflow and its secrets, the full `.env` reference |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | Day-2 operations: users, roles, audit retention, deleted records, imports/exports, attachments, failed jobs, scheduler troubleshooting, settings, notification preferences, demo data, common preflight failures, backups, the weekly summary, routine tasks, task mentions and the task report |
| [docs/GoLive_Checklist.md](docs/GoLive_Checklist.md) | Go-live checklist (steps 12 and 13): every go-live item with its evidence, date, result and owner |
| [docs/Static_Analysis_Known_False_Positives.md](docs/Static_Analysis_Known_False_Positives.md) | PHPStan level 5 without baseline or suppressions; how a genuine false positive would be recorded |
| [docs/OPEN_DECISIONS.md](docs/OPEN_DECISIONS.md) | The 13 questions as asked on 2026-09-04 (historical, all resolved) |

## Stack (pinned to the established environment)

Laravel `^13.0` · Filament `^5.0` · PHP `^8.3` (Herd 8.4 locally) · MySQL 8.4 · spatie/laravel-permission `^8` ·
spatie/laravel-activitylog `^4.12` · bezhansalleh/filament-language-switch `^5` · PHPUnit `^12` · Larastan `^3` ·
Laravel Pint · Vite 8 · Tailwind 4.

## Local environment notes (verified on the development machine)

- Run every PHP/Composer command through **Laravel Herd's** PHP 8.4 (`composer …` in PowerShell resolves to Herd).
  In Git Bash the bare `php` resolves to XAMPP PHP 8.2, which cannot run Laravel 13.
- Local MySQL 8.4 listens on 127.0.0.1:3306. The CRM uses databases `crm` and `crm_testing`.
  Never start XAMPP's MariaDB on port 3306.
- The site is served by Herd at `http://crm.test` (`herd link crm`).
- `view:cache` is overridden by `App\Console\Commands\CacheViewsCommand`. The framework's version compiles each
  template under `SplFileInfo::getRealPath()`, which Windows returns fully back-slashed, while a request resolves the
  same template through `FileViewFinder`, which joins with a forward slash. The two strings hash to different compiled
  filenames, so on Windows the stock `view:cache` reports success while leaving the cache the request reads stone
  cold. The override compiles both spellings; on Linux and macOS they are byte-identical and it compiles exactly what
  the framework's does.
