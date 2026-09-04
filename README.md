# CRM

Bilingual (Arabic + English, RTL + LTR), light/dark, production-grade CRM built on **Laravel 13 + Filament 5 + MySQL 8**,
following the engineering standards established by the Stockflow (ZonKSA) project.

## Status

| Phase | State |
|---|---|
| 1 — Discovery (CRM project, Stockflow reference, environment) | ✅ done 2026-09-04 |
| 2 — Architecture plan | ✅ delivered 2026-09-04 — see `docs/` |
| 3 — Critical questions | ⏳ awaiting the owner — [docs/OPEN_DECISIONS.md](docs/OPEN_DECISIONS.md) |
| 4 — Foundation (scaffold, auth, roles, lang, theme, audit, tests) | not started |
| 5+ — Modules, integration, quality pass, production readiness | not started |

No application code exists yet. The Laravel application will be scaffolded at the repository root once the
open decisions are answered.

## Documents

| Document | Purpose |
|---|---|
| [docs/ARCHITECTURE_PLAN.md](docs/ARCHITECTURE_PLAN.md) | Discovery findings, product scope, system architecture, module dependencies, implementation order, testing, security, performance, deployment, folder structure, risks, decision register |
| [docs/DATABASE_DESIGN.md](docs/DATABASE_DESIGN.md) | Every proposed table, column, key, index and integrity rule |
| [docs/STOCKFLOW_COMPARISON.md](docs/STOCKFLOW_COMPARISON.md) | Stockflow vs CRM comparison table, reuse classification (must / should / CRM-specific / must not), conflict resolution |
| [docs/OPEN_DECISIONS.md](docs/OPEN_DECISIONS.md) | The 13 questions that must be answered before implementation, each with a recommended default |

## Planned stack (pinned to the established environment)

Laravel `^13.0` · Filament `^5.0` · PHP `^8.3` (Herd 8.4 locally) · MySQL 8.4 · spatie/laravel-permission `^8` ·
spatie/laravel-activitylog `^4.12` · bezhansalleh/filament-language-switch `^5` · PHPUnit `^12` · Larastan `^3` ·
Laravel Pint · Vite 8 · Tailwind 4.

## Local environment notes (verified on the development machine)

- Run every PHP/Composer command through **Laravel Herd's** PHP 8.4 (`composer …` in PowerShell resolves to Herd).
  In Git Bash the bare `php` resolves to XAMPP PHP 8.2, which cannot run Laravel 13.
- Local MySQL 8.4 listens on 127.0.0.1:3306. The CRM will use databases `crm` and `crm_testing`.
  Never start XAMPP's MariaDB on port 3306.
- The site will be served by Herd at `http://crm.test` (`herd link crm`).
