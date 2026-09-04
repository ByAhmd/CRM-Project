# Open decisions — answers required before implementation

Date raised: 2026-09-04. Each question lists the options, the recommended default (marked ★) and the
consequence. Answering with the question number and a letter is enough (e.g. `Q1 A, Q2 A, …`).
Any question left unanswered will be implemented with the ★ default and recorded in `DECISIONS.md`.

Everything not listed here is an implementation detail already decided in
[ARCHITECTURE_PLAN.md §12.1](ARCHITECTURE_PLAN.md#121-decided-by-the-architect-implementation-details-within-the-established-standard).

---

## Q1 — Production hosting target (architecture, cost)

- **A ★** Hostinger shared hosting like Stockflow: no Node, no Supervisor, `exec()` disabled; database queue drained by the scheduler (≤ 60 s notification latency); assets built in CI; **the database engine (MySQL 8 vs MariaDB) must be confirmed in writing before the first migration**.
- **B** VPS / managed host with MySQL 8, Supervisor and Node: persistent queue worker, instant imports/notifications, optional Reverb later.
- **C** Undecided — plan for A (the stricter constraints) and keep B possible.

## Q2 — Tenancy (architecture, one-way door)

- **A ★** Single organisation: no tenant column; organisation settings are a singleton; visibility is owner/team only.
- **B** Multi-organisation from day one: `organization_id` + fail-closed global scope on every business table (Stockflow's `BelongsToCompany` pattern), spatie `teams` feature on, isolation test suite.
- **C** Filament native tenancy (tenant switcher, per-tenant roles).

## Q3 — Permission source of truth (architecture, security)

- **A** Stockflow style: role→permission matrix in code is authoritative; spatie tables are a projection; roles are not editable at runtime; no Roles UI.
- **B ★** spatie tables are the runtime authority: typed `Permission` enum keys; defaults seeded from a code matrix (drift test pins the defaults); super admins edit roles/permissions in an own bilingual **Roles** resource; every change audited.
- **C** Same as B but using Filament Shield's generated permissions and UI (PascalCase `ViewAny:Lead` keys, Shield translations, `shield:generate`).

## Q4 — Record visibility and teams (business rule, security)

- **A** Hard-coded by role: sales rep sees own records, sales manager sees their team, admin/support/read-only see all.
- **B** Per-user visibility setting (own / team / all) chosen on the user form, independent of role.
- **C ★** Permission-driven: `{entity}.view_team` / `{entity}.view_all` permissions per role (defaults: rep = own, manager = team, admin/support/read-only = all) plus `teams` with one team per user and an optional team manager; reassignment is audited and notified.

Also confirm: may a sales rep **reassign** their own records to someone else (★ no — only users with `{entity}.assign`, i.e. managers/admins)?

## Q5 — Default language (product)

- **A ★** Arabic default, English fallback (Stockflow's convention; RTL first).
- **B** English default, Arabic fallback.

Either way both languages are complete and each user's choice is remembered.

## Q6 — Company vs Account and the customer lifecycle (business rule)

- **A ★** One `accounts` entity with a `type` (prospect / customer / partner / other). A prospect becomes a **customer automatically when its first deal is won** (`customer_since` set); admins may also set the type manually.
- **B** Separate `companies` (directory) and `customers` (accounts with commercial relationship) tables.
- **C** One `accounts` entity; the customer flag is set manually only.

Also confirm: a contact belongs to **one** account (★) or may belong to several?

## Q7 — Lead statuses, qualification, scoring and conversion (business rules)

Default status set to seed (editable later): **New → Contacted → Qualified → Converted**, plus **Unqualified** (terminal, reopenable). Confirm or provide your own list.

Qualification: moving to a status of kind *qualified* stamps `qualified_at/by` and requires a qualification note (★ note optional). No BANT/checklist fields in v1 unless you specify them.

Scoring:
- **A** Manual score 0–100 entered by the rep.
- **B ★** Rule-based: configurable points per source, per status, per activity recency and per filled field, recalculated by a service, shown as a badge, with an optional manual override.
- **C** No scoring in v1.

Conversion: creates or links an Account (from `company_name`), creates a Contact, optionally creates a Deal in the default pipeline, marks the lead converted (read-only afterwards). ★ Leads may only be converted from a *qualified* status (confirm, or allow conversion from any open status).

## Q8 — Deals: amount model and currency (business rule, data)

- **A** Single manual `amount` per deal, one currency.
- **B ★** Product catalogue + line items (quantity, unit price, discount) with the deal amount computed from the lines, single currency; manual amount allowed when there are no lines.
- **C** Line items and multi-currency with stored exchange rates.

Currency and timezone to configure: ★ **SAR** and **Asia/Riyadh** (both editable in General settings).
Forecast categories (Pipeline / Best case / Commit / Omitted) and stage probabilities with per-deal override: ★ include.

## Q9 — Custom fields (architecture, data)

- **A** Typed custom-field engine in v1 for Leads, Contacts, Accounts and Deals (definitions + typed values, filterable, importable, exportable).
- **B ★** Typed engine in v1 for Leads and Deals only; Contacts/Accounts in a later release.
- **C** Defer entirely to v2 (no JSON bags will be used as a substitute).

## Q10 — Email (external service, business rule)

- **A** Emails are logged manually as activities only.
- **B ★** A plus sending templated emails to contacts from the CRM through the application mailer (SMTP you provide), logged automatically; no open/click tracking.
- **C** Mailbox synchronisation (IMAP/Gmail) — a separate project, not v1.

Also: do you have an SMTP provider for production mail (notifications, invitations)? If not, mail stays on the `log` driver and only in-app notifications are delivered until one is configured.

## Q11 — Authentication and password policy (security)

- MFA: **A ★** available to every user, optional; **B** required for super_admin and admin; **C** required for everyone. (Email MFA needs a working mailer; app MFA works offline.)
- Password policy: ★ minimum 12 characters, mixed case, a number, not in known breaches (`uncompromised`), session lifetime 120 minutes with secure cookies in production. Confirm or change.
- Self-registration: ★ off (invite only).

## Q12 — Kanban board and calendar implementation (package decision)

- **A ★** Build both as custom Filament pages: kanban with Filament's bundled SortableJS, calendar with FullCalendar (MIT, npm). Fully bilingual/RTL/dark-controlled and testable; ~400–600 lines each.
- **B** Use MIT packages that declare Filament 5 support: `relaticle/flowforge` 4.1 (kanban) and `guava/calendar` 3.2 (calendar). Faster, but RTL/dark fidelity and upgrade behaviour are outside our control.
- **C** Kanban custom, calendar via `guava/calendar`.

## Q13 — Retention and data policy (business rule)

- Audit ledger retention: ★ 730 days (configurable), then pruned weekly. Timeline entries are permanent.
- Soft-deleted records: ★ kept indefinitely, restorable by admins; permanent deletion never offered in the UI.
- Export permissions: ★ only sales managers and above may export; reps may export their own view only if you say so.

---

## Not questions — already settled by convention (for your information)

- Stack versions, single admin panel, folder structure, Filament resource composition, PHPUnit/PHPStan/Pint gates, CI content, database naming rules, spatie activitylog as the audit ledger, own attachments table on a private disk, Filament built-in import/export, Filament global search, own saved views, six default roles (`super_admin`, `admin`, `sales_manager`, `sales_rep`, `support`, `read_only`), duplicate detection by normalised email/phone with a merge action, configurable lookups as bilingual rows, notes with edit history, immutable activities, recurring tasks (daily/weekly/monthly), in-app notifications with mail opt-in when a mailer exists.
