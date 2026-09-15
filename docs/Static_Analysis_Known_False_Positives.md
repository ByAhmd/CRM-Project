# Static analysis — known false positives

## Current state

**None.** PHPStan (Larastan `^3`) runs at **level 5** with `checkModelProperties: true` and `phpVersion: 80300` (the
production floor, D-1) over `app`, `config`, `database`, `routes` and `tests`, with **no baseline file and no
suppressions** — no `ignoreErrors` entry in `phpstan.neon.dist`, no `@phpstan-ignore` comment in project code (A-13). `composer analyse` must report 0 errors before every commit (`composer check`), and CI runs the
same analysis on PHP 8.3.

```bash
composer analyse    # vendor/bin/phpstan analyse --memory-limit=1G
```

## A finding is fixed, not silenced

Almost every PHPStan finding in this codebase is a real gap, and the fix belongs in the code:

- A model property PHPStan does not know → a `@property` docblock for the column or cast (CLAUDE.md section 3: every
  enum, date and decimal cast is documented, because Larastan reads the database schema, not `casts()`).
- An untyped relation → the relation generics (`@return BelongsTo<Account, $this>`).
- A mixed value from `config()`, a request or a collection → a cast or a type check at the boundary.
- A dead branch or an impossible comparison → remove the branch, or correct the type that made it impossible.

Adding a baseline, raising `ignoreErrors`, lowering the level or writing `@phpstan-ignore` is not an option for a
finding in project code.

## When a genuine false positive appears

A false positive is a finding that stays wrong after the code is typed correctly — typically a vendor stub or a Larastan
extension that does not model a framework behaviour. Before recording one:

1. Reproduce it on the current `composer.lock` versions and confirm the code is correct at runtime (a test exercises
   the line).
2. Try the typed alternatives first (a narrower docblock, an `assert()`/`instanceof`, a generic annotation).
3. Search the Larastan and PHPStan issue trackers; note the issue link if one exists.

Then add an entry below and, only then, a single narrowly scoped `ignoreErrors` entry in `phpstan.neon.dist` that
matches the exact message **and** the exact path (never a pattern that could hide other findings, never
`reportUnmatchedIgnoredErrors: false`, never a baseline). Reference the entry number in a comment next to it.

### Entry template

```markdown
### FP-<n>: <short title>

| Field | Value |
|---|---|
| Date | YYYY-MM-DD |
| File and line | `app/...php:<line>` |
| Message | the exact PHPStan message |
| Versions | phpstan/phpstan x.y.z, larastan/larastan x.y.z (from composer.lock) |
| Why it is false | what the code does at runtime and which stub/extension misreads it — quote the vendor line that proves it (file and line in vendor/) |
| Proof at runtime | the test that exercises the line |
| Typed alternatives tried | what was tried and why it does not remove the finding |
| Upstream issue | link, or "none found" |
| Ignore rule | the exact `ignoreErrors` entry added (message + path) |
| Remove when | the upstream fix or version after which the entry and the rule are deleted |
```

Review the list on every dependency update: run the analysis without the rule, and delete the entry and its rule once
the finding no longer appears.

## Entries

None.
