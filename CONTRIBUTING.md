# Contributing

## Branches

| Branch | Purpose |
|---|---|
| `master` | Production (A-14). Releases are built and deployed from it through *Actions → Deploy* ([docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)); it only receives work that passed `composer check` and a manual walk. |
| `develop` | Integration branch. Work lands here first. |
| `develop-<firstname>` | Personal working branches (e.g. `develop-ahmed`). |

Never rewrite history on `master` or `develop`; never force-push; never delete branches you did not create.

## Commits

Conventional commits: `type(scope): subject`, scope optional, imperative mood, lower case after the colon. Types in use:
`feat`, `fix`, `docs`, `test`, `refactor`, `chore`. The scope names the module(s); the subject names the plan step or
decision when there is one. Examples from the history:

```
feat(custom-fields): typed custom-field engine on leads, contacts, accounts and deals (step 11, D-9)
feat(search,views,import-export): query-builder filters, saved views, CSV/XLSX import and export (step 9)
fix(quality): step 12 quality pass — authorisation, security, schema, performance, localisation and test hardening
docs: record owner decisions D-1..D-13 and apply them to the plan
chore: scaffold Laravel 13 + Filament 5 with the project quality gates
```

Body: `- ` bullets (or short grouped paragraphs for a large change) explaining what and why, with the decision
references that apply (`D-7`, `A-11`), and a verification line: `composer check green (N tests / M assertions)`.

## Before every commit

```bash
composer check      # composer lint (pint --test) → composer analyse (phpstan, level 5) → composer test (phpunit)
```

Run PHP and Composer through **Laravel Herd's PHP 8.4** (PowerShell resolves them; in Git Bash the bare `php` is XAMPP
8.2, which cannot run Laravel 13). CI runs the same gates on PHP 8.3 and MySQL 8.4, so no PHP 8.4-only syntax.

Pint, PHPStan level 5 and the full PHPUnit suite must all pass. A PHPStan finding is fixed at the root; a genuine false
positive is documented in [docs/Static_Analysis_Known_False_Positives.md](docs/Static_Analysis_Known_False_Positives.md)
with the vendor line that proves it, never suppressed inline and never baselined.

## Test databases

Tests run on **MySQL 8.4, never SQLite**, and rebuild their database on every run (`RefreshDatabase`).

- `phpunit.xml` points at `crm_testing`; `composer check` uses it.
- For parallel work (several engineers or agents on one machine) the local server also has `crm_testing_a` …
  `crm_testing_j`, created like `crm_testing` and granted to the `crm` user. Choose one with the environment:

  ```bash
  DB_DATABASE=crm_testing_c vendor/bin/phpunit tests/Feature/System
  ```

- **Only one PHPUnit process may run at a time on a machine**, even on different databases: `Storage::fake()` uses the
  same `storage/framework/testing/disks/<disk>` directory for every process, so two runs wipe each other's fake files
  and fail at random. Separate databases only prevent schema collisions.
- Never run `artisan migrate` (or any test) against the development database `crm`.

## Decisions

A change to business rules, schema strategy, authorisation model, external services, packages or hosting needs an
entry in `docs/DECISIONS.md` **before** the code. Document first, then implement. A change to deployment, the
scheduler, `.env` keys or artisan commands updates [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) and
[docs/OPERATIONS.md](docs/OPERATIONS.md) in the same commit — `tests/Feature/System/DeploymentDocsTest.php` fails when a
scheduled entry, a command or an `.env` key is missing from them.

## Pull requests

Target `develop`. Include: what changed, decision references, screenshots for UI changes in both locales and both
themes, and the verification line.
