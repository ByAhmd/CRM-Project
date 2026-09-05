# Contributing

## Branches

| Branch | Purpose |
|---|---|
| `master` | Production. Deploy trigger. Only fast-forwarded from `develop` after `composer check` and a full manual walk. |
| `develop` | Integration branch. All work lands here first. |
| `develop-<firstname>` | Personal working branches (e.g. `develop-ahmed`). |

Never rewrite history on `master` or `develop`; never force-push; never delete branches you did not create.

## Commits

Conventional subjects without scope, imperative mood, under 72 characters:

```
feat: lead conversion workflow with account/contact/deal creation
fix: deal board refused stage moves for team-visible deals
docs: ADR-004 attachment storage
test: visibility isolation for tasks
refactor: extract RunsWorkflowActions from view pages
chore: bump filament to 5.7.9
```

Body: `- ` bullets explaining what and why, a decision reference when one applies (`D-7`, `A-11`,
`ADR-003`), and a verification line: `composer check green (N tests / M assertions)`.

## Before every commit

```bash
composer check
```

Pint, PHPStan level 5 and the full PHPUnit suite on MySQL `crm_testing` must all pass. A PHPStan finding is
fixed at the root; a genuine false positive is documented in `docs/Static_Analysis_Known_False_Positives.md`
with the vendor line that proves it, never suppressed inline.

## Decisions

A change to business rules, schema strategy, authorisation model, external services, packages or hosting
needs an entry in `docs/DECISIONS.md` (and an ADR in `docs/decisions/` when the reasoning is longer than a
table row) **before** the code. Document first, then implement.

## Pull requests

Target `develop`. Include: what changed, decision references, screenshots for UI changes in both locales and
both themes, and the verification line.
