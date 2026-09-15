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
| 5 — Modules, plan steps 2–10 (lookups → accounts/contacts → leads → deals → conversion → activities → notifications → search/import → dashboard/reports) | ✅ done 2026-09-07 — lookups, accounts & contacts, leads, deals & pipelines (stage workflow, line items, kanban), lead conversion, activities / notes / attachments / tasks, timeline and calendar, notifications and templated email, search / saved views / import-export, dashboard and nine reports |
| Step 11 — Custom fields (D-9) | ✅ done 2026-09-07 — typed custom fields on leads, contacts, accounts and deals: forms, tables, filters, import and export |
| Step 12 — Quality pass (security, performance, localisation and RTL, theme, schema on MySQL 8.4 and MariaDB 10.4, authorisation matrix, test review) | ✅ done 2026-09-15 — steps 0–12 complete; open go-live items with their owners in [docs/GoLive_Checklist.md](docs/GoLive_Checklist.md) |
| Step 13 — Production readiness (deployment pipeline and runbook, trusted proxies, clean install, demo data) | ✅ engineering done 2026-09-15 — deploy workflow, [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md), [docs/OPERATIONS.md](docs/OPERATIONS.md), production preflight, trusted proxies, scheduler heartbeat, `app:demo-data`; clean install, rollback and the whole suite proven on MySQL 8.4 and MariaDB 10.4; real database-queue import/export smoke run; EXPLAIN at 40× volume. Go-live rows that need the host or the owner stay open in [docs/GoLive_Checklist.md](docs/GoLive_Checklist.md) |

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
php artisan app:demo-data   # optional: bilingual demo dataset through the real services (8 demo users, one password
                            # printed once); never in production; remove it with `php artisan app:demo-data --fresh`
```

The reference seed is idempotent and never overwrites an administrator's edits: `php artisan db:seed --force` after a
deploy only recreates missing rows.

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
| [docs/DECISIONS.md](docs/DECISIONS.md) | Owner decisions D-1 … D-13 and architect decisions A-1 … A-21 |
| [docs/PERMISSIONS.md](docs/PERMISSIONS.md) | Seeded roles and their default grants, record scope (own / team / all) and the guards above the permissions |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Hostinger runbook: prerequisites, first deployment, the cron line and what it drives, releases, rollback, PHP limits, backups, logs, monitoring, the deploy workflow and its secrets, the full `.env` reference |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | Day-2 operations: users, roles, audit retention, deleted records, imports/exports, attachments, failed jobs, scheduler troubleshooting, settings, demo data, common preflight failures |
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
