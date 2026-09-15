# CRM — Operations

Day-2 operations of a running installation. Installing and releasing are in [DEPLOYMENT.md](DEPLOYMENT.md); who may do
what is in [PERMISSIONS.md](PERMISSIONS.md). Panel locations are given by their English labels (*group → item*); the
Arabic interface has the same structure. Commands run from the application directory (release folders:
`DEPLOY_PATH/current`) with the host's PHP 8.3 binary. After any change to `.env`, rebuild the caches:
`php artisan optimize` and `php artisan filament:optimize` — a cached configuration ignores `.env` until then.

Contents: 1 Users · 2 Roles, permissions and teams · 3 Audit ledger · 4 Deleted records · 5 Imports and exports ·
6 Attachments and disk growth · 7 Queue and failed jobs · 8 Scheduler · 9 Currency and timezone · 10 Lookups,
custom fields and email templates · 11 Notification preferences · 12 Demo data · 13 Command reference ·
14 Common preflight failures.

---

## 1. Users

Accounts are invite-only (D-11). User administration is *System → Users* and needs `users.manage`; anything touching a
super admin needs `roles.manage` (A-12).

| Task | How | Notes |
|---|---|---|
| **Invite** | *System → Users → Invite user*: name, e-mail, role, team, language. | The account is created **Invitation pending** and an invitation mail with a password-set link is sent; the link expires after 60 minutes (`config/auth.php`, `passwords.users.expire`). Setting the password activates the account. **Needs working SMTP**: under the log mailer nothing is delivered (DEPLOYMENT.md, section 3.6). |
| **Resend an invitation** | *Resend invitation* on the pending user's row. | Sends a new link; the previous link stops working. Offered only while the account is still pending. |
| **Disable** | Edit the user, *Status → Disabled*. | A disabled account cannot sign in; its records, history and audit trail are kept. A page the user still has open is refused on its **next** request (Filament re-checks `User::canAccessPanel()` on every Livewire request; `tests/Feature/Access/OpenPageAfterStatusChangeTest.php`). Disabled and pending users receive no notifications, cannot be chosen as owners, and reach no import/export file even with a live session. |
| **Re-enable** | Edit the user, *Status → Active*. | Pending is reached only through an invitation. |
| **Hand over their records** | On each list, the *assign* action (single or bulk) with `{entity}.assign`. | Every change of owner goes through `RecordAssignmentService`: audited as `{entity}.assigned` and notified to the new owner. Sales reps cannot reassign (D-4). |
| **Delete / restore** | *Delete* on the edit page (a soft delete); to undo, filter the list by *Deleted* and use *Restore* (single or bulk). | Nothing is ever deleted permanently (`forceDelete` is refused everywhere, D-13). A deleted user cannot sign in. |
| **Password reset** | The user requests it on the sign-in page. | Needs SMTP; throttled. |
| **MFA** | Each user enables it from their own profile: an authenticator app or e-mail codes. | Optional for everyone (D-11). E-mail codes need SMTP; until it exists, use the authenticator app. |

Guards that hold whatever the permissions say: nobody disables, deletes or sets back to pending their own account; the
last active super admin cannot be demoted, disabled, set to pending or deleted; only a `roles.manage` holder invites,
edits, disables, deletes or restores a super admin.

If the only super admin is locked out (lost password and no SMTP, lost MFA device), `app:onboard` does **not** help — it
refuses once a super admin exists. Recovery is an engineer's operation on the host's database; record what was done
and why. Keeping two active super admins avoids the situation.

## 2. Roles, permissions and teams

- *System → Roles and permissions* (`roles.manage`, super admins only). The spatie tables are the runtime authority
  (D-3); every role or permission change is written to the audit ledger by `RoleService`. `super_admin` is locked and
  always holds every permission.
- **After a release that adds permission keys:** `php artisan db:seed --force` (the deploy does it) creates the new
  rows, re-grants `super_admin` everything and removes rows for keys the code no longer declares. The seeder sets the
  grants of the other five roles only when it creates them: an existing role keeps its current grants whether or not
  anybody has edited it, so a super admin grants the new keys to those roles on the Roles screen.
  Until the seed runs, `app:preflight` fails with "The permissions table has N rows; the catalogue … has M".
- Record scope follows `{entity}.view_any` (own), `{entity}.view_team` (team) and `{entity}.view_all` (all).
- *Settings → Teams* (`teams.manage`): one team per user, an optional manager; team scope follows the team membership.

## 3. Audit ledger

- *System → Audit log* (`audit.view`). Append-only; sign-ins, record changes, reassignments, settings and role changes.
- **Retention (D-13):** 730 days by default. `activitylog:clean --days=<CRM_AUDIT_RETENTION_DAYS> --force` runs weekly
  (Sunday 00:00). To change the window set `CRM_AUDIT_RETENTION_DAYS` in `.env` and rebuild the caches — the scheduled
  command reads the value from the cached configuration. Record timelines (activities, notes, status and stage
  history) are permanent and are not pruned.
- A manual run is the same command: `php artisan activitylog:clean --days=730 --force`.

## 4. Deleted records

- Business records are soft-deleted and kept indefinitely (D-13). Lists with a *Deleted* filter: leads, contacts,
  accounts, deals, tasks, products, email templates, pipelines, competitors, teams and users. Attachments are restored
  from the record's attachments panel.
- A deleted record is **frozen**: every write verb refuses it; *Restore* is the only action left (A-20). Restore needs
  the entity's `restore` permission and the record inside the actor's scope (tasks have no `task.restore` key:
  restoring a task is granted with `task.delete`). Sales reps cannot delete or restore leads, contacts, accounts or deals.
- There is no permanent deletion in the panel. Removing personal data for good is a deliberate engineer operation
  outside the application, recorded with its reason.

## 5. Imports and exports

- Started from the lists of leads, contacts, accounts and deals (import and export) and of activities and tasks
  (export). Imports need `{entity}.import` plus the policy verbs the rows use; exports see only the exporter's scope
  (D-13).
- Bounds: imports up to 5 000 rows (chunks of 100), exports up to 20 000 rows (chunks of 500); 5 imports and 10 exports
  per client IP address per list page per minute (Filament's action rate limit is keyed on the request's IP address,
  not on the user). Behind an untrusted proxy every user shares that limit (DEPLOYMENT.md, section 3.6,
  `CRM_TRUSTED_PROXIES`).
- Both run on the queue: nothing happens until the scheduler's drain picks them up (section 8). Completion notices
  arrive in the bell.
- History: *System → Imports* and *System → Exports*. A user always sees and downloads their own runs;
  `imports.view` / `exports.view` open other users' runs only for entities the holder reaches at all level. An import
  run shows its failed rows and offers them as a CSV download.
- **Pruning:** `model:prune` runs daily. Import runs are kept 90 days (`Import::RETENTION_DAYS`), export runs and their
  files 30 days (`Export::RETENTION_DAYS`); failed import rows leave with their import.

## 6. Attachments and disk growth

Everything lives on the private `local` disk, `storage/app/private` (release folders:
`DEPLOY_PATH/shared/storage/app/private`):

| Directory | Content | Pruned |
|---|---|---|
| `crm/<entity>/<id>/` | Attachments (UUID file names) | Never. A deleted attachment is soft-deleted and keeps its file so it can be restored. |
| `tmp/` | Uploads of forms that were never submitted | Daily (`attachments:prune-temporary`, older than a day). |
| `livewire-tmp/` | Livewire's temporary uploads, import CSV/XLSX files included (an import reads its file but never deletes it) | Daily (`uploads:prune-livewire-temporary`, older than a day); Livewire also clears them when the next upload starts. |
| `filament_exports/<export id>/` | Export files | With the export run after 30 days (`model:prune`). |
| `reports/` | Report downloads being streamed | Hourly (`reports:prune-downloads`, older than an hour). |

Watch the growth monthly over SSH: `du -sh storage/app/private/*` (or the host's disk-usage page). The attachment size
limit is `CRM_ATTACHMENT_MAX_KB` (10 240 KB by default; Livewire's temporary upload caps any upload at 12 MB). The
attachments directory is the part of the disk to back up with the database (DEPLOYMENT.md, section 7).

## 7. Queue and failed jobs

- `QUEUE_CONNECTION=database`; there is no persistent worker. The scheduler runs
  `queue:work --stop-when-empty --max-time=50` every minute (D-1).
- A job that throws is recorded in `failed_jobs` (`QUEUE_FAILED_DRIVER=database-uuids`). Failed-job records older than
  seven days are deleted weekly (`queue:prune-failed --hours=168`) — look at them at least weekly.

```bash
php artisan queue:failed                 # list: id (uuid), connection, queue, class, failed at
php artisan queue:retry <uuid>           # push one back onto the queue (the next drain runs it)
php artisan queue:retry all              # push every failed job back
php artisan queue:forget <uuid>          # delete one record after dealing with it
php artisan queue:work --stop-when-empty # drain now instead of waiting for the next minute
```

Read the exception in `storage/logs/laravel-YYYY-MM-DD.log` before retrying: a job that failed on bad data fails again.
`RescoreLeads` is tried once and rescheduled daily; a failed run needs no retry.

## 8. Scheduler

All scheduled work depends on the one cron line (DEPLOYMENT.md, section 3.10). Nothing fails loudly when it stops —
reminders, the queue drain and the prunes just stop happening — so the entry `scheduler:heartbeat` stamps the cache key
`scheduler.heartbeat` every minute (kept 10 minutes), and `app:preflight` warns when the stamp is missing or older than
five minutes.

| Symptom | Check | Fix |
|---|---|---|
| Preflight: "No scheduler heartbeat" | Is the cron entry present? Does its PHP binary print 8.3 (`<php> -v`)? Is the site in maintenance mode (`storage/framework/down` exists)? | Restore the cron line with the absolute PHP 8.3 path; `php artisan up` if a release was left down. |
| Heartbeat present, but reminders, notifications or imports do not arrive | `php artisan schedule:list` (next due times); `php artisan queue:failed`; the log. | Run `php artisan schedule:run` by hand and read its output; fix the failing job. |
| Heartbeat warning right after a release | Maintenance mode pauses the scheduler and `optimize:clear` empties the cache. | Expected; rerun `app:preflight` two minutes after `php artisan up`. |
| An entry never runs after a killed process | `withoutOverlapping` locks expire on their own (10 minutes for the drain, 5 for the task passes). | Wait, or `php artisan schedule:clear-cache` to release the locks. |
| The heartbeat "cannot be read from the cache" | `CACHE_STORE`, cache directory permissions or the `cache` table. | Fix the cache store (DEPLOYMENT.md, section 3.6). |

`schedule:run` itself can be run by hand at any time; every task command is idempotent (reminders, overdue notices and
stale-lead notices are stamped once sent).

## 9. Currency and timezone

*Settings → General settings* (`settings.manage`): organisation name, currency, timezone, week start. Every change is
audited. `CRM_CURRENCY` and `CRM_TIMEZONE` only seed these values on the first seed; editing `.env` later changes
nothing.

- **Currency (D-8).** Single currency. No amount is ever converted. A new deal stores the current currency code; an
  existing deal keeps the code it was created with on its own page, while the deal list, line items, the deal board,
  dashboard widgets and reports label amounts with the current setting. Choose the currency before real deals exist; changing it later relabels
  figures without converting them.
- **Timezone.** The organisation timezone decides how date-times are shown and entered in the panel and how report and
  dashboard buckets fold days and months (A-19, A-20). Stored values stay in `APP_TIMEZONE` (Asia/Riyadh), so changing
  the setting changes the display only. **Never change `APP_TIMEZONE` once data exists** — stored date-times would be
  misread. Scheduled times (`leads:notify-stale` at 07:00, `RescoreLeads` at 03:00) follow `APP_TIMEZONE`, not the
  setting.

## 10. Lookups, custom fields and email templates

All under *Settings* and bilingual: every name has an Arabic and an English value.

- **Lookups** (`settings.manage`): *Lead statuses*, *Lead sources*, *Industries*, *Pipelines* (with their stages),
  *Activity types*, *Close reasons*, *Competitors*, *Tags*, *Lead scoring*. A lookup in use cannot be deleted —
  deactivate it instead; existing records keep their value. Keep at least one active
  default lead status, a status of kind *converted*, and an active default pipeline with a default stage: `app:preflight`
  fails without them. System activity types cannot be deleted. `php artisan db:seed --force` recreates missing
  reference rows without overwriting edits.
- **Products** (*Sales → Products*, `product.*` permissions): the catalogue for deal line items.
- **Custom fields** (*Settings → Custom fields*, `settings.manage`; D-9) on leads, contacts, accounts and deals. The key
  is how imports, exports and saved views address the field and cannot be changed later; the type is locked once the
  field holds values; a field with values cannot be deleted — deactivate it, and it disappears from forms, tables and
  filters while the values are kept.
- **Email templates** (*Settings → Email templates*, `email_template.*` permissions; D-10): bilingual subject and plain
  text body with merge tags written `{{tag}}` (the form lists the tags available), offered on leads, contacts or both.
  Inactive templates are not offered. Sending records an outbound e-mail activity in every case; delivery needs SMTP.

## 11. Notification preferences

*System → Notification preferences*, per user, for themselves: each event (assignments, task reminders and overdue
notices, deal stage changes and closes, lead conversions, stale leads, mentions) can be switched off for the bell.
The e-mail choice is kept but only takes effect once a real mail transport is configured (the page says so while the
log mailer is active). Invitations are always sent by mail. Notifications reach only users who can open the record and
can sign in.

## 12. Demo data

`app:demo-data` seeds a bilingual demo dataset **through the real services** (status and stage logs, notifications and
audit rows included), with history spread over the last 90 days — for local walks, staging reviews and `EXPLAIN` on
volume.

```bash
php artisan app:demo-data                 # asks for confirmation; runs the reference seed first
php artisan app:demo-data --force         # no confirmation (a non-interactive run without --force is cancelled)
php artisan app:demo-data --scale=10      # 1 to 50: multiplies accounts, contacts, leads, deals, tasks and activities
php artisan app:demo-data --fresh         # removes exactly what the recorded run created, then stops; asks for confirmation
php artisan app:demo-data --fresh --force # the same without confirmation (needed in a non-interactive run)
php artisan app:demo-data --fresh --allow-orphans # remove the demo users even when other records still reference them
```

- **Refused in production**: with `APP_ENV=production` it exits 1 before reading or writing anything.
- Refused while an earlier run is recorded (settings key `demo.registry`) — run `--fresh` first — and when a demo
  e-mail, team name or product code or name is already taken.
- Creates 8 active demo users — `super_admin@`, `admin@`, `sales_manager@`, `sales_rep@`, `support@`, `read_only@`,
  plus `sales_manager2@` and `sales_rep2@` for the second team, all `@demo.crm.test` — sharing one random password
  printed **once** at the end of the run (only its hash is stored). Mail stays off for every demo user. At scale 1 it
  creates 2 teams, 6 products, 19 accounts, 39 contacts, 40 leads, 26 deals and the related tasks, activities, notes,
  attachments, saved views and audit rows; the command prints the per-table counts.
- `--fresh` deletes the recorded rows **permanently** (demo data only; soft deletes protect real work), plus the demo
  users' roles, notifications, sessions, reset tokens and export files, the demo attachment files and the registry. It
  refuses while a real contact, deal or line item still points at a demo account or product. Reference data and real
  users are never touched.
- `--fresh` also refuses, exits 1 and changes nothing while rows the recorded run did **not** create reference a demo
  user — for example contacts imported while signed in as `admin@demo.crm.test` (their `owner_id` and `created_by`),
  that import run, an export run, a task assigned to a demo user, or an audit entry a demo user caused. It lists every
  such table and column with its count and what removing the users would do: owner and author columns are set to
  NULL (the records survive, but reps no longer see them), import and export runs are deleted with their user
  (`cascadeOnDelete`), and audit entries keep pointing at a user that no longer exists. The list is read from the
  database's own foreign keys on `users` plus the audit ledger's causer and subject. Reassign those records to a real
  user (or delete them) and run `--fresh` again. `--allow-orphans` removes the demo users anyway after printing the
  same list — use it only when those records are disposable. The demo users' own saved views and notification
  preferences never block the removal; they go with the users.

## 13. Command reference

| Command | Purpose |
|---|---|
| `php artisan app:onboard [--name=] [--email=] [--password=]` | Reference seed plus the first active super admin (DEPLOYMENT.md, section 3.9). |
| `php artisan app:preflight [--json]` | Production gate: failures exit 1, warnings exit 0 (section 14, DEPLOYMENT.md section 3.12). |
| `php artisan app:demo-data [--force] [--fresh] [--allow-orphans] [--scale=N]` | Demo dataset; never in production (section 12). |
| `php artisan tasks:send-reminders` | Task reminders now (scheduled every five minutes; idempotent). |
| `php artisan tasks:notify-overdue` | Overdue notices now (scheduled every fifteen minutes; idempotent). |
| `php artisan leads:notify-stale` | Stale-lead notices now (scheduled daily at 07:00; idempotent). |
| `php artisan schedule:list` / `schedule:run` / `schedule:clear-cache` | Inspect, run or unlock the scheduler (section 8). |
| `php artisan queue:failed` / `queue:retry` / `queue:forget` | Failed jobs (section 7). |
| `php artisan db:seed --force` | Recreate missing reference rows and permission keys; never overwrites edits. |
| `php artisan down --with-secret` / `php artisan up` | Maintenance mode (DEPLOYMENT.md, section 4). |
| `php artisan optimize` / `filament:optimize` / `optimize:clear` | Caches (DEPLOYMENT.md, section 3.11). |

## 14. Common preflight failures

`php artisan app:preflight` (or `--json`). The full list with every message is in DEPLOYMENT.md, section 3.12; these
are the ones a running installation meets.

| Output | Cause on a running site | Fix |
|---|---|---|
| FAIL `N migrations are pending` | A release was uploaded without `migrate`. | `php artisan migrate --force`. |
| FAIL `The permissions table has N rows; the catalogue … has M` | A release changed the permission catalogue without `db:seed`, or a rollback crossed one. | `php artisan db:seed --force`. |
| FAIL `No active super admin` | The last super admin was disabled or deleted outside the panel's guards (database edit). | Reactivate or restore that account (engineer, on the host). |
| FAIL `No active default lead status` / `No lead status of kind "converted"` / `No active default pipeline` / `The default pipeline has no default stage` | An administrator deactivated or changed the default lookup. | Fix it in *Settings*, or `php artisan db:seed --force`. |
| FAIL `The database cannot be reached or read` | Database password changed at the host, database down, quota. | Update `DB_*`, rebuild caches; contact the host. |
| FAIL `storage/logs is not writable` (or `storage/app`, `bootstrap/cache`) | Files uploaded with wrong permissions or owner; disk quota full. | DEPLOYMENT.md, section 3.7; free disk space. |
| FAIL `APP_DEBUG is true in production` / `QUEUE_CONNECTION is sync` / `CACHE_STORE is array` / `SESSION_SECURE_COOKIE is not true` / `APP_URL is not https` | `.env` edited by hand. | Restore the production value, rebuild caches. |
| FAIL `Required PHP extension … not loaded` / `PHP x is running` | The host changed the PHP version or its extensions. | Select PHP 8.3 and the extensions again (web and CLI); check the cron binary. |
| WARN `No scheduler heartbeat` / `heartbeat is N minutes old` | Cron entry removed, PHP binary changed, site left in maintenance mode. | Section 8. |
| WARN `Not cached: …` | Caches cleared after a `.env` edit or release. | `php artisan optimize`, `php artisan filament:optimize`. |
| WARN `MAIL_MAILER is "log"` | SMTP not configured yet (D-10). | Configure `MAIL_*`, rebuild caches. |
| WARN `CRM_TRUSTED_PROXIES is not set` | The proxy setting was never made. Behind the host's TLS-terminating proxy, invitation and password-reset links then answer 403, and everybody shares the sign-in, import and export throttles (they are keyed on the client IP address, which is the proxy's). | Run the `curl … \| grep -i strict-transport-security` check of DEPLOYMENT.md section 3.6; set the host's proxy list (or `*` under its stated condition), rebuild the caches, check again. |
| WARN `SESSION_LIFETIME is N minutes` | `.env` edited. | `SESSION_LIFETIME=120`, rebuild caches. |
