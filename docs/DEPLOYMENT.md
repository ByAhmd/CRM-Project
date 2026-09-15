# CRM — Deployment runbook

Production target: **Hostinger shared hosting** (decision D-1): no persistent worker, no Supervisor, no Node on the
server, one cron line drives the scheduler, and the scheduler drains the database queue. This runbook describes the
tree as it is; every command, option and setting below exists in the code. Day-2 work (users, retention, failed jobs,
settings) is in [OPERATIONS.md](OPERATIONS.md); open go-live items are tracked in
[GoLive_Checklist.md](GoLive_Checklist.md).

Placeholders used throughout — replace them, never commit the real values:

| Placeholder | Meaning |
|---|---|
| `/path/to/app` | Absolute path of the application directory (the one holding `artisan`). Outside the web root. |
| `<web root>` | The directory the domain serves, usually the domain's `public_html` (hPanel's File Manager shows where it is). |
| `<php>` | The PHP 8.3 command-line binary on the host (section 3.10 explains why the exact path matters). |
| `https://crm.example.com` | The production URL. `example.com` is a reserved documentation domain, not a real host. |

Contents: 1 Prerequisites · 2 Build a release · 3 First deployment · 4 Subsequent releases · 5 Rollback ·
6 PHP limits · 7 Backups · 8 Logs · 9 Monitoring · 10 Security reminders · 11 The deploy workflow and its secrets ·
12 `.env` reference.

---

## 1. Prerequisites

| Requirement | Detail |
|---|---|
| PHP **8.3** or newer, for the web **and** the command line | The language floor (D-1, A-1). `app:preflight` fails on anything older (`App\Support\System\PlatformRequirements::MINIMUM_PHP`). The website's PHP version and the CLI `php` are selected separately on many shared hosts — check both. |
| PHP extensions | Required by `composer.json` and checked by `app:preflight`: `dom`, `fileinfo`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `xmlreader`, `zip`. Also used by the framework and normally compiled in: `ctype`, `filter`, `hash`, `iconv`, `json`, `libxml`, `pcre`, `session`, `tokenizer`. Composer's platform check does **not** prove all of them: `composer check-platform-reqs` reports `ext-mbstring` and `ext-ctype` as provided by `symfony/polyfill-mbstring` and `symfony/polyfill-ctype`, so an install succeeds without those native extensions — `app:preflight` and `<php> -m` on the host are the real check. |
| PHP functions | `proc_open` for the command-line PHP: `schedule:run` starts every scheduled artisan command as a child process. |
| Database | **MySQL 8** or **MariaDB 10.4+**. Both are proven (A-21: migrations, rollback, seeding and the full suite on MySQL 8.4.11 and MariaDB 10.4.32). **Confirm the engine and exact version with the host in writing before the first production migration** (D-1), e.g. `SELECT VERSION();` in phpMyAdmin, and record it in `docs/DECISIONS.md`. If it is MariaDB other than 10.4, rerun the MariaDB checks on that version first. Create an empty database and a user with all privileges on it; character set `utf8mb4`. |
| Domain with SSL | The site must be served over https: `app:preflight` fails in production on a non-https `APP_URL` and on `SESSION_SECURE_COOKIE` not true (D-11). |
| Cron | One entry running every minute (section 3.10). Confirm the plan's minimum cron interval. |
| SSH (if the plan offers it) | Makes every step a single command. Without SSH, use the File Manager / FTP path and run artisan through temporary cron entries (section 3.13). The optional deploy job of the workflow (section 11) needs SSH and `rsync` on the host. |
| Outgoing mail (later) | SMTP credentials for `MAIL_*` (D-10). Until they exist the log mailer is used and **invitations, password resets and mail notifications are not delivered** (section 3.6). |
| Build machine | Node 22 (`package.json` requires `>=22.12`) and PHP 8.3 with Composer — GitHub Actions (section 2) or a local machine. Never on the host. |

---

## 2. Build a release

The host never runs `npm`. Assets are compiled in CI or locally and shipped in `public/build` (D-1).

**Option A — the deploy workflow (recommended).** In GitHub: *Actions → Deploy → Run workflow*, choose the branch
(`master` for production, A-14) and type a release note. The `build` job runs on PHP 8.3 and Node 22:

1. `composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist`
2. `npm ci` and `npm run build`
3. writes `RELEASE.txt` (release name, commit, branch, build time, note)
4. packs `crm-<release>.tar.gz` — the whole checkout **including `vendor/` and `public/build`**, excluding `.git`,
   `.github`, `node_modules`, `tests` and `.env` — and uploads it as the artifact `crm-release-<release>` (kept 30
   days). `<release>` is `YYYYMMDDHHMMSS-<first 12 characters of the commit>`.

Download the artifact from the run page (GitHub wraps it in a zip; the tarball is inside). The `deploy` job does
nothing unless its secrets exist and the run was dispatched from `master` (section 11).

**Option B — build locally** (same result):

```bash
git clone <repository> crm-release && cd crm-release     # a clean checkout: no .env, no dev caches
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build                                   # produces public/build/manifest.json
tar -czf ../crm-release.tar.gz --exclude=./.git --exclude=./node_modules --exclude=./tests --exclude=./.env .
```

`composer install` runs the `post-autoload-dump` scripts (`package:discover`, `filament:upgrade`); they need no
database and no `.env`. `filament:upgrade` clears the configuration, route and view caches, which is why the caches
are always rebuilt after an install (section 3.11).

---

## 3. First deployment

### 3.1 Choose a layout

| Layout | Use when | Directory structure |
|---|---|---|
| **Single directory** | No SSH, or deploying by hand | `/path/to/app` holds the application; each release is copied over it. |
| **Release folders** | SSH is available; required by the deploy workflow | `DEPLOY_PATH/releases/<release>/` (one per release), `DEPLOY_PATH/shared/.env`, `DEPLOY_PATH/shared/storage/`, and the symlink `DEPLOY_PATH/current` → `releases/<release>`. `/path/to/app` is then `DEPLOY_PATH/current`. |

Either way the application directory is **outside the web root**; only `public/` is ever served.

### 3.2 Get the release onto the host

- **git clone (SSH).** `git clone <repository> /path/to/app`, then `git checkout` the release commit. A clone has no
  `vendor/` (section 3.3) and no `public/build` (`/public/build` is git-ignored): copy `public/build` from a release
  archive or a local `npm ci && npm run build` into `/path/to/app/public/build`.
- **Release archive (SSH).** Upload `crm-<release>.tar.gz` (scp/sftp) and unpack it:
  `mkdir -p /path/to/app && tar -xzf crm-<release>.tar.gz -C /path/to/app`.
- **Release archive (File Manager / FTP, no SSH).** Upload the tarball with hPanel's File Manager into a new
  directory beside (not inside) `<web root>` and extract it there with the File Manager's extract action — or unpack
  it locally and upload the files over FTP/SFTP. Check afterwards that `vendor/autoload.php`,
  `public/build/manifest.json` and the hidden `public/.htaccess` arrived (some FTP clients skip dot-files).

### 3.3 PHP dependencies

- With SSH and Composer on the host (`composer --version` answers):
  `cd /path/to/app && composer install --no-dev --optimize-autoloader --no-interaction`.
- Without Composer (or without SSH): use the release archive, which already contains `vendor/` built without dev
  packages. Ship `vendor/` together with `bootstrap/cache/packages.php` and `bootstrap/cache/services.php` from the
  **same** `composer install --no-dev` (the release archive does). Never upload a `vendor/` built with dev packages
  (debug and test tooling would run in production), and never a `packages.php` / `services.php` generated by a
  different install: they name the service providers to boot, so a mismatch boots providers of packages that are
  not in the uploaded `vendor/`, or skips ones that are.

### 3.4 Front-end assets

`public/build` must come from CI or a local build (`npm ci && npm run build`), **never** from the host. The Filament
assets under `public/css/filament`, `public/js/filament` and `public/fonts/filament` are committed and republished by
`filament:upgrade` during `composer install`.

### 3.5 Document root

**Preferred:** point the domain's document root at `/path/to/app/public` (release folders:
`DEPLOY_PATH/current/public`). If the host lets you choose the directory a domain serves, this is all.

When the host forces `<web root>` (`public_html`) as the document root, use one of these fallbacks. Never place the
application itself inside `public_html` and rely on rewrite rules to hide it: if `mod_rewrite` is off or an `.htaccess`
is lost, `.env` (database password, `APP_KEY`), `storage/logs` and `vendor/` become downloadable.

**Fallback 1 — symlink (needs SSH and a host that follows symlinks).** Keep a copy of the original first.

```bash
mv <web root> <web root>.orig
ln -s /path/to/app/public <web root>
```

Apache must be allowed to follow symlinks for the account (`FollowSymLinks` or `SymLinksIfOwnerMatch`); if the site
answers 403 afterwards, the host does not allow it — restore `<web root>.orig` and use fallback 2. Security: only
`public/` is exposed, exactly as with a proper document root. The link survives releases in the release-folders layout
because `current` is switched, not the web root.

**Fallback 2 — forwarding `index.php` (works without SSH).**

1. Copy **everything** inside `/path/to/app/public/` into `<web root>`: `.htaccess`, `favicon.ico`, `robots.txt`,
   `build/`, `css/`, `js/`, `fonts/` (static files are served from `<web root>`).
2. Replace `<web root>/index.php` with this file, setting `$base` to the absolute application path:

```php
<?php

// <web root>/index.php — forwards every request to the CRM installed outside the web root.
// $base is the absolute path of the application directory (the one holding artisan).
$base = '/path/to/app';

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = $base.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $base.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $base.'/bootstrap/app.php';

$app->handleRequest(Illuminate\Http\Request::capture());
```

The `.htaccess` copied from `public/` already sends every non-file request to this `index.php`. Security: `.env`,
`storage/` and `vendor/` stay outside the web root and are never reachable; the web root holds only public files and
a short forwarder, so nothing is exposed beyond what `public/` exposes. Operational cost: `<web root>` holds a **copy**
of the public files, so step 1 must be repeated after every release (which is why this fallback does not suit the deploy
workflow, section 11)
(otherwise browsers load stale `build/` assets while PHP reads the new manifest) — and the copied `index.php` must not
be overwritten by the one from `public/`. `storage:link` is not needed: nothing is served from the `public` disk
(attachments and exports live on the private `local` disk, A-6).

### 3.6 Production `.env`

Create `/path/to/app/.env` (release folders: `DEPLOY_PATH/shared/.env`) from `.env.example`, set the values below, and
restrict it to the account: `chmod 600 .env` when PHP runs as the account's own user, `640` with PHP's group otherwise
(section 3.7). `.env.example` lists every key the configuration reads, each with a
"Local:" and "Production:" comment; section 12 is the full reference. The keys that matter on day one:

| Key | Production value | Why |
|---|---|---|
| `APP_ENV` | `production` | Switches on the production checks of `app:preflight`, forces https URLs (`AppServiceProvider`), refuses `app:demo-data`. |
| `APP_KEY` | generated: `php artisan key:generate` (writes it into `.env`) | Encrypts sessions and encrypted casts. Generate **once**; changing it logs everyone out and makes encrypted values unreadable. When rotating, put the old key in `APP_PREVIOUS_KEYS`. |
| `APP_DEBUG` | `false` | `app:preflight` fails on `true` in production (stack traces and configuration would be exposed). |
| `APP_URL` | `https://crm.example.com` | Must start with `https://` (preflight fails otherwise); links, signed URLs and mail are built from it. |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` / `APP_TIMEZONE` | `ar` / `en` / `Asia/Riyadh` | D-5, D-8. `APP_TIMEZONE` is the storage timezone — never change it once data exists (OPERATIONS.md). |
| `DB_CONNECTION` | `mysql` (or `mariadb` when the host confirmed MariaDB) | Both drivers work (A-21). |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | exactly what hPanel lists for the database | Set on the server only. `DB_SOCKET` only if the host requires a socket. |
| `SESSION_DRIVER` | `file` (or `database`; the `sessions` table exists) | Both survive requests on one server. |
| `SESSION_SECURE_COOKIE` | `true` | Cookies over https only (D-11); preflight fails otherwise in production. |
| `SESSION_LIFETIME` | `120` | D-11; preflight warns on any other value in production. |
| `QUEUE_CONNECTION` | `database` | Drained by the scheduler (D-1); preflight fails on `sync` in production. |
| `CACHE_STORE` | `file` or `database` | The scheduler's locks, the heartbeat, login throttling and the settings and permission caches live here; preflight fails on `array` in production. |
| `LOG_STACK` / `LOG_LEVEL` / `LOG_DAILY_DAYS` | `daily` / `warning` / `14` | Section 8. |
| `MAIL_MAILER` | `log` until SMTP exists, then `smtp` | Section below. |
| `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_EHLO_DOMAIN`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | the organisation's SMTP account | Only once SMTP exists (D-10). `MAIL_FROM_ADDRESS` on the organisation's domain. |
| `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` | blank | Read by `app:onboard` only; section 3.9. |
| `CRM_CURRENCY` / `CRM_TIMEZONE` | `SAR` / `Asia/Riyadh` | Seeded into General settings on the first seed only; later changes are made in the panel (D-8). |
| `CRM_LEAD_STALE_DAYS` | `14` | Days without activity before an open lead is reported stale. |
| `CRM_AUDIT_RETENTION_DAYS` | `730` | D-13; used by the weekly `activitylog:clean`. |
| `CRM_ATTACHMENT_MAX_KB` | `10240` | Largest attachment; keep the PHP upload limits above it (section 6). |
| `CRM_TRUSTED_PROXIES` | the host's proxy addresses, or `*` (see below) | HTTPS detection behind the host's proxy. |

**Mail until SMTP exists (D-10).** With `MAIL_MAILER=log` nothing is delivered: invitations, password resets and mail
notifications are written to the log instead (preflight warns). The log mailer writes at level `debug`, so with the
production `LOG_LEVEL=warning` those messages — including their password-set links — are **not even in the log**. The
first super admin is created with `app:onboard` and needs no mail; inviting anybody else waits for SMTP. Do not lower
`LOG_LEVEL` in production to fish invitation links out of the log: the log would then hold live password-set links.

**`CRM_TRUSTED_PROXIES` and its trade-off.** On shared hosting TLS usually ends at the host's proxy, which forwards
plain HTTP to PHP and reports the original scheme and client in `X-Forwarded-*` headers.
`App\Http\Middleware\TrustProxies` believes those headers only from the peers this setting names:

- unset / `null` — trust nobody. Safe against spoofed headers, but **it breaks the site behind a TLS-terminating
  proxy**, because every request then looks like plain http from the proxy's address. Preflight warns in production.
  The consequences:
  - **Invitation and password-reset links answer 403.** Links are signed as `https://...` (`APP_URL`, and
    `URL::forceScheme('https')` in production), but the signature check rebuilds the URL from the request's own scheme,
    which is `http`. Filament's password-reset route carries the `signed` middleware, and invitations use the same
    route (`UserInvitationNotification`), so no invited user can set a password.
  - **Shared throttles.** Filament's sign-in page allows 5 attempts per minute per client IP address, and the import
    and export actions 5 and 10 per minute per client IP address per list page. When every request comes from the
    proxy's address, the whole organisation shares each of those limits: one mistyped password burst locks everyone
    out for a minute.
  - The HSTS header is not sent (`SecurityHeaders` sends it only on a secure request).
  - Sign-in audit rows (successful and failed sign-ins, `RecordAuthActivity`) record the proxy's IP instead of the
    client's.
- a comma-separated list of IPs or CIDR ranges — trust only those peers. **Preferred** whenever the host publishes its
  proxy addresses (ask the host; do not guess).
- `*` — trust whoever connects. Correct only when PHP is reachable **solely** through the host's proxy; otherwise any
  client can send its own `X-Forwarded-For` and `X-Forwarded-Proto`, spoofing its IP address in the sign-in, import
  and export throttles and in the sign-in audit rows, and claiming https.

The value is read from configuration on every request, so it keeps working after `config:cache`.

**Check it on the host** (after the caches are built, section 3.11):

```bash
curl -sI https://crm.example.com/admin/login | grep -i strict-transport-security
```

The header is sent only when PHP sees the request as https. If the command prints nothing while the site is served over
https, the proxy is not trusted: set `CRM_TRUSTED_PROXIES` to the host's proxy list (or `*` under the condition above),
run `php artisan optimize` and `php artisan filament:optimize` again, and repeat the check. Recording the result is an
open go-live row (checklist section 3).

### 3.7 File permissions

PHP must be able to write `storage/` (all of it, including `storage/app/private`, `storage/framework/*` and
`storage/logs`) and `bootstrap/cache`. `app:preflight` fails when `storage/app`, `storage/logs` or `bootstrap/cache`
is not writable. When PHP runs as the account's own user (check with the host), directories `755` and files `644` are
enough:

```bash
cd /path/to/app
find storage bootstrap/cache -type d -exec chmod 755 {} \;
find storage bootstrap/cache -type f -exec chmod 644 {} \;
chmod 600 .env                     # only when PHP runs as the account's own user
```

If PHP runs as a different user than the one uploading files, give that user's group write access (`775`/`664`)
instead, and make `.env` readable by that group only: `chmod 640 .env` with the file's group set to PHP's group
(`600` would leave PHP unable to read it and every request would fail). Never `777`, and never a world-readable `.env`.

### 3.8 Database

```bash
cd /path/to/app
php artisan migrate --force        # --force: production refuses to migrate without it
php artisan db:seed --force        # reference data: roles and permissions, settings, lead sources and statuses,
                                   # industries, default pipeline and stages, system activity types, close reasons,
                                   # email templates — idempotent, never overwrites an administrator's edits
```

Run the migration only after the engine and version are confirmed (section 1).

### 3.9 First super admin

Accounts are invite-only (D-11), so the first super admin is created on the command line. `app:onboard` runs the
reference seed again (idempotent), then creates an **active** `super_admin`; it refuses once any super admin exists
and refuses a password that fails the policy (12 characters, mixed case, a number, not found in known breaches).

```bash
php artisan app:onboard            # prompts for name, e-mail and password (the password prompt is hidden)
```

Options for non-interactive use: `--name=`, `--email=`, `--password=`; each falls back to `ADMIN_NAME`,
`ADMIN_EMAIL`, `ADMIN_PASSWORD` from the configuration, then to the prompt. Keep the password out of shell history and
the process list:

- **Best:** pass `--name` and `--email` only and type the password at the hidden prompt.
- **No interactive terminal** (e.g. the cron route of section 3.13): put the values in `ADMIN_NAME`, `ADMIN_EMAIL`,
  `ADMIN_PASSWORD` in `.env`, run `php artisan config:clear` first if the configuration is cached (a cached
  configuration does not see new `.env` values), run `app:onboard`, then **blank the three keys again** and rebuild the
  caches (section 3.11).
- Never `--password=...` on a shared shell; if it happened, run `history -d <n>` for that line (or clear the history)
  and change the password after signing in.

Sign in at `https://crm.example.com/admin`. The owner then enables MFA on their own account (optional for everybody,
D-11) and invites the team once SMTP works.

### 3.10 The cron line

Everything scheduled — the queue drain, reminders, retention — depends on **one** cron entry:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

**The PHP binary.** Cron runs with a minimal `PATH`, and the `php` it finds is not necessarily the version selected for
the website in hPanel. `schedule:run` starts every scheduled artisan command with **the same PHP binary** that runs
`schedule:run` itself, so a wrong binary breaks every entry at once. Use the absolute path of the PHP 8.3 CLI:

```cron
* * * * * cd /path/to/app && <php> artisan schedule:run >> /dev/null 2>&1
```

Find `<php>` over SSH (`command -v php`, then `<candidate> -v` must print 8.3 or newer), or ask the host. Hosts that
offer several PHP versions install each under its own path (for example `/opt/alt/php83/usr/bin/php` on CloudLinux
based hosts) — confirm the exact path exists before using it. In hPanel's cron form use the custom-command type so the
whole line above is yours. Release folders: `cd DEPLOY_PATH/current`. If the plan's minimum interval is longer than one
minute, everything below runs late by that much. With an interval above five minutes the heartbeat stamp is older than
five minutes (or already expired after its ten minutes) for much of every interval, so `app:preflight` warns about the
heartbeat on most runs.

What the one line drives (`routes/console.php`; every entry is `onOneServer()`; times in `APP_TIMEZONE`,
Asia/Riyadh). While the site is in maintenance mode (`php artisan down`) **no** entry runs:

| Entry | Cadence | What it does |
|---|---|---|
| `queue:work --stop-when-empty --max-time=50` | every minute; `withoutOverlapping(10)`; skipped when the queue connection is `sync` | Drains the database queue (notifications, imports, exports, templated e-mail, `RescoreLeads`) and stops when empty or after 50 seconds, so it never becomes the long-lived process the host kills (D-1). |
| `scheduler:heartbeat` (named callback) | every minute | Stores the current time under the cache key `scheduler.heartbeat` for 10 minutes; `app:preflight` warns when it is missing or older than 5 minutes. |
| `tasks:send-reminders` | every five minutes; `withoutOverlapping(5)` | Task reminders whose `reminder_at` has passed; idempotent (`reminder_sent_at`). |
| `tasks:notify-overdue` | every fifteen minutes; `withoutOverlapping(5)` | Overdue notices to assignees; idempotent (`overdue_notified_at`). |
| `leads:notify-stale` | daily at 07:00 | Tells the owner of each open lead quiet for `CRM_LEAD_STALE_DAYS` days, once (`stale_notified_at`). |
| `RescoreLeads` (queued job) | daily at 03:00 | Queued; the next drain recomputes open lead scores in batches of 500 so activity-recency points expire (D-7). |
| `attachments:prune-temporary` (named callback) | daily at 00:00 | Deletes abandoned uploads older than a day under `tmp/` on the attachments disk. |
| `uploads:prune-livewire-temporary` (named callback) | daily at 00:00 | Deletes Livewire temporary uploads older than a day under `livewire-tmp/` (`livewire.temporary_file_upload.directory`) on the upload disk — import CSV/XLSX files included, which the import never deletes and which would otherwise stay in `storage/app/private` and its backups. |
| `activitylog:clean --days=<CRM_AUDIT_RETENTION_DAYS> --force` | weekly, Sunday 00:00 | Audit retention, 730 days by default (D-13). |
| `queue:prune-failed --hours=168` | weekly, Sunday 00:00 | Deletes failed-job records older than seven days. |
| `model:prune --model=App\Models\Import --model=App\Models\Export` | daily at 00:00 | Import history after 90 days (`Import::RETENTION_DAYS`), export history and files after 30 days (`Export::RETENTION_DAYS`). |
| `reports:prune-downloads` (named callback) | hourly | Deletes report export files older than an hour that an aborted download left behind. |

`php artisan schedule:list` prints the same list from the running code.

### 3.11 Caches

```bash
php artisan optimize               # config, events, routes, views, blade icons — and filament:optimize, which Filament
                                   # registers with the framework's optimize command
php artisan filament:optimize      # Filament component and icon caches; repeated explicitly, as preflight's hint says
```

Rebuild the caches after **every** change to `.env` and after every release: a cached configuration ignores `.env`
until `php artisan config:cache` (part of `optimize`) runs again. `php artisan optimize:clear` removes them all — and
also empties the application cache (section 4).

### 3.12 Preflight — the final gate

```bash
php artisan app:preflight          # exit 0 = may go live; exit 1 = stay in maintenance mode
php artisan app:preflight --json   # {"status":"ok"|"failed","failures":[...],"warnings":[...]}, same exit codes
```

Failures (exit 1):

| Message begins with | Meaning | Fix |
|---|---|---|
| `PHP x is running; the CRM needs PHP 8.3.0 or newer` | The CLI PHP running artisan is too old. | Use the 8.3 binary (section 3.10); select 8.3 in hPanel. |
| `Required PHP extension is not loaded` / `Required PHP extensions are not loaded` | One of `dom, fileinfo, intl, mbstring, openssl, pdo_mysql, xmlreader, zip` is missing. | Enable it in the host's PHP configuration (for the CLI too). |
| `APP_KEY is not set` | No encryption key. | `php artisan key:generate`, then `php artisan optimize`. |
| `APP_DEBUG is true in production` | Stack traces would be public. | `APP_DEBUG=false`, rebuild caches. |
| `APP_URL is not https in production` | Links and signed URLs would use http. | `APP_URL=https://...`, rebuild caches. |
| `SESSION_SECURE_COOKIE is not true` | Session cookies could travel over http (D-11). | `SESSION_SECURE_COOKIE=true`, rebuild caches. |
| `QUEUE_CONNECTION is sync` | Queued work would run inside web requests (D-1). | `QUEUE_CONNECTION=database`, rebuild caches. |
| `CACHE_STORE is array` | Nothing survives a request: scheduler locks, login throttling, settings and permission caches break. | `CACHE_STORE=file` or `database`, rebuild caches. |
| `The attachments disk "local" is not defined` / `is rooted under public/` | Private files would be missing or downloadable without authorisation (D-13). | Restore `config/filesystems.php` from the release; the `local` disk is `storage/app/private`. |
| `storage/app is not writable` (or `storage/logs`, `bootstrap/cache`) | Uploads, logs or caches cannot be written. | Section 3.7. |
| `The database cannot be reached or read` | Wrong `DB_*`, database down, or missing privileges. | Check `DB_*` against hPanel; rebuild caches. |
| `The database has never been migrated` / `N migration(s) pending` | Schema not current. | `php artisan migrate --force`. |
| `The permissions table has N rows; the catalogue ... has M` | The permission catalogue changed (new release) or was never seeded (D-3). | `php artisan db:seed --force`. |
| `No active super admin` | Nobody can administer roles or invite users (A-12). | `php artisan app:onboard` (fresh install), or reactivate a super admin. |
| `No active default lead status` / `No lead status of kind "converted"` | Leads cannot be created or converted (D-7). | `php artisan db:seed --force`, or fix the statuses in *Settings → Lead statuses*. |
| `No active default pipeline` / `The default pipeline has no default stage` | Deals cannot be created (D-8). | `php artisan db:seed --force`, or fix *Settings → Pipelines*. |
| `System activity type is missing for` / `System activity types are missing for` | Task completion, conversion and e-mail logging would fail (A-4). | `php artisan db:seed --force`. |

Warnings (exit 0) — operable, but read them:

| Message begins with | Where | Meaning / action |
|---|---|---|
| `MAIL_MAILER is "log"` (or `"array"`) | everywhere | Nothing is delivered (D-10). Configure SMTP when it exists. |
| `No scheduler heartbeat` / `The last scheduler heartbeat is N minutes old` / `... unreadable` / `... cannot be read from the cache` | everywhere | `schedule:run` is not running every minute. Check the cron line and its PHP binary (section 3.10). Expected right after a release: maintenance mode pauses the scheduler and `optimize:clear` empties the cache — rerun preflight two minutes after `php artisan up`. |
| `SESSION_LIFETIME is N minutes; the decided lifetime is 120` | production | Set `SESSION_LIFETIME=120` (D-11). |
| `Not cached: configuration, routes, views, Filament components` | production | `php artisan optimize` and `php artisan filament:optimize`. |
| `CRM_TRUSTED_PROXIES is not set` | production | Behind a TLS-terminating proxy this breaks invitation and reset links (403) and shares the sign-in throttle; run the `curl` check of section 3.6. |

### 3.13 Running artisan without SSH

Without SSH, each one-off command runs through a **temporary** cron entry that writes its output to a file, which you
read in the File Manager and then delete together with the entry:

```cron
* * * * * cd /path/to/app && <php> artisan migrate --force > storage/logs/deploy-migrate.txt 2>&1
```

Remove the entry as soon as the file appears (it would run again every minute; `migrate` and `db:seed` are harmless
when repeated, `key:generate` is **not** — for the key, generate one locally with `php artisan key:generate --show`
and paste it into `.env`). Run the steps in order, one entry at a time: `migrate --force`, `db:seed --force`,
`app:onboard` (with `ADMIN_*` in `.env`, section 3.9), then **blank `ADMIN_NAME`, `ADMIN_EMAIL` and `ADMIN_PASSWORD`
in `.env`** in the File Manager (otherwise the next step caches the plaintext password into
`bootstrap/cache/config.php`), then `optimize`, `filament:optimize`, `app:preflight`. Then add the permanent cron line
of section 3.10.

### 3.14 First deployment, in order

1. Prerequisites confirmed, database created, engine and version recorded (section 1).
2. Release on the host (3.2), dependencies (3.3), `public/build` present (3.4), document root (3.5).
3. `.env` written, `php artisan key:generate`, permissions (3.6, 3.7).
4. `php artisan migrate --force`, `php artisan db:seed --force` (3.8).
5. `php artisan app:onboard` (3.9).
6. Cron line (3.10).
7. `php artisan optimize`, `php artisan filament:optimize` (3.11).
8. `php artisan app:preflight` exits 0 (3.12). Two minutes later it shows no heartbeat warning.
9. Open `https://crm.example.com/up` (200) and sign in at `/admin`.

---

## 4. Subsequent releases

### 4.1 By hand (single directory)

```bash
cd /path/to/app
php artisan down --with-secret     # prints a secret; https://crm.example.com/<secret> lets you in while others see 503
                                   # (or --secret=<phrase> to choose it; add --retry=60 to send Retry-After)
# upload the new release over the application directory (archive, rsync or git checkout; public/build included)
composer install --no-dev --optimize-autoloader --no-interaction   # skip when vendor/ came in the archive
php artisan optimize:clear         # before migrate: the previous release's cached config and events must not be used
php artisan migrate --force
php artisan db:seed --force        # creates new permission keys and reference rows; preflight fails without them
php artisan optimize
php artisan filament:optimize
php artisan app:preflight          # exit 1: stay down, fix, repeat from the failing step
php artisan up
```

- Fallback 2 of section 3.5: copy the new `public/` files (not `index.php`) into `<web root>` before `up`.
- Uploading over a live directory leaves a window where old and new files mix; that is what maintenance mode covers.
  Delete files the new release no longer has (an archive extracted over the old tree does not remove them).
- `optimize:clear` also runs `cache:clear`: the settings and permission caches are rebuilt on the next request, and
  the scheduler heartbeat is gone until the scheduler runs again after `up` — the heartbeat warning right after a
  release is expected.
- While the site is down the scheduler runs nothing; reminders and queued work catch up after `up` (every task
  command is idempotent).

### 4.2 Through the deploy workflow (release folders)

Run *Actions → Deploy* (section 11). The `deploy` job uploads the release into `DEPLOY_PATH/releases/<release>` and
runs, on the host: link `shared/.env` and `shared/storage` → `php artisan down --retry=60` in the live release →
`migrate --force` → `db:seed --force` → `optimize:clear` → `optimize` → `filament:optimize` → `app:preflight` in the
new release → switch `current` atomically → `php artisan up` → keep the five newest releases. If any step fails,
`current` is not switched and the site stays in maintenance mode; the job log names the failing step.

After `current` changes, PHP's realpath cache can keep serving files of the previous release for up to
`realpath_cache_ttl` seconds (PHP's default is 120); the release is fully live after that.

---

## 5. Rollback

1. `php artisan down` (if the site is not already down). Take a database dump (section 7).
2. **Database — only if the bad release ran migrations, and only those.** `php artisan migrate:status` shows the
   batch of each migration; one `migrate --force` run is one batch. Roll back **while the bad release's code is still
   in place** (its migrations' `down()` methods do the work): `php artisan migrate:rollback --force` rolls back the
   last batch only. Never add `--step` beyond the release's own migrations, and never `migrate:fresh` or
   `migrate:reset` in production. Skip this step when the bad release added no migration.
3. **Code:** put the previous release back — release folders:
   `cd DEPLOY_PATH && ln -sfn releases/<previous> current.next && mv -Tf current.next current`; single directory: upload
   the previous archive again (keep recent archives for this reason) and delete files the previous release did not
   have.
4. `php artisan optimize:clear` (the bad release's cached configuration and events must not be used), then
   `php artisan db:seed --force` from the restored code — the permission seeder prunes permission rows its catalogue
   does not know, so preflight's permission count matches again.
5. `php artisan optimize`, `php artisan filament:optimize`, `php artisan app:preflight`, `php artisan up`.

**The DATETIME rollback refusal.** The migrations `2026_09_14_400006` … `2026_09_14_400010` widened workflow moments
(on leads, deals, lead status logs, deal stage logs and notes) from `TIMESTAMP` to `DATETIME`. Their `down()` refuses
to run while any value lies outside the `TIMESTAMP` range (1970-01-01 00:00:01 to 2038-01-19 03:14:07 UTC), naming the
table, the column and the offending rows, before any DDL runs (`App\Support\Database\TimestampRange`). This is
deliberate: the conversion would otherwise stop part-way or silently zero those values. Do not edit the rows to force
the rollback; stop the rollback at the refusing migration and keep the wider columns.

---

## 6. PHP limits

Set them in the host's PHP configuration for the website (and check the CLI values with `<php> -i`):

| Setting | Value | Reason |
|---|---|---|
| `upload_max_filesize` | at least `CRM_ATTACHMENT_MAX_KB` (default 10240 KB) — `12M` covers the default | Attachments and import files are uploaded through Livewire's temporary upload, whose own unpublished default rule is `max:12288` (12 MB); a larger `CRM_ATTACHMENT_MAX_KB` has no effect beyond 12 MB. |
| `post_max_size` | above `upload_max_filesize`, e.g. `16M` | The upload request carries the file plus form fields. |
| `memory_limit` | `256M` (web and CLI) | XLSX import and export (OpenSpout), report exports and the queue drain. |
| `max_execution_time` | web: `120`; CLI: `0` (the CLI default) | Report exports are written and streamed in the request; queued work runs in the CLI drain, which stops itself after 50 seconds (`--max-time=50`). |

Built-in bounds that keep the work inside those limits: imports up to 5 000 rows in chunks of 100, exports up to 20 000
rows in chunks of 500, 5 imports and 10 exports per client IP address per list page per minute
(`App\Filament\Support\ImportExportActions`; behind an untrusted proxy every user shares that limit, section 3.6);
`RescoreLeads` has `$timeout = 45` under the 50-second drain and `DB_QUEUE_RETRY_AFTER=90`.

---

## 7. Backups

Nothing in the tree backs anything up; the owner decides the retention (checklist section 12).

- **Database, daily.** The host's backup feature, if the plan has one, plus an own dump kept outside the web root.
  Credentials in a `~/.my.cnf` with mode `600`, never on the command line:

  ```cron
  30 2 * * * mysqldump --defaults-extra-file=$HOME/.my.cnf --single-transaction --routines --no-tablespaces <database> | gzip > $HOME/backups/crm-$(date +\%F).sql.gz
  ```

  (`%` must be escaped as `\%` in crontab.) Delete dumps older than the agreed retention; copy them off the host.
  Without SSH use phpMyAdmin's export.
- **Private storage disk.** `storage/app/private` (release folders: `DEPLOY_PATH/shared/storage/app/private`):
  attachments under `crm/<entity>/<id>/`, export files under `filament_exports/` (pruned after 30 days), report
  downloads under `reports/` (pruned hourly). Attachments are the part that cannot be recreated — back them up with
  the same cadence as the database, so a restore finds the files its rows point at.
- **`.env`**, stored separately and securely (it holds `APP_KEY`: without it, encrypted values in a restored database
  cannot be read).
- **Restore rehearsal:** load a dump into a scratch database, point a copy of the app at it, run `app:preflight`
  (open checklist row).

---

## 8. Logs

- `LOG_CHANNEL=stack`, `LOG_STACK=daily`: one file per day, `storage/logs/laravel-YYYY-MM-DD.log`, and the daily
  handler deletes files beyond `LOG_DAILY_DAYS` (14) itself — no host log rotation is needed.
- `LOG_LEVEL=warning` in production. Exceptions from web requests, queued jobs and scheduled commands are reported
  there (the cron line discards console output).
- Look at the log and at `php artisan queue:failed` weekly (OPERATIONS.md).

---

## 9. Monitoring

- **Uptime:** an external monitor on `https://crm.example.com/up` (Laravel's health route; 200 when the application
  boots). Choosing the monitor and the alert recipient is an owner item.
- **Preflight from cron, alerting on failure only:** hourly, keeping the last result in `storage/logs` (not
  web-reachable) and printing it **only** when something is wrong:

  ```cron
  0 * * * * cd /path/to/app && <php> artisan app:preflight --json > storage/logs/preflight-last.json 2>&1; rc=$?; if [ "$rc" -ne 0 ] || grep -qi 'scheduler heartbeat' storage/logs/preflight-last.json; then cat storage/logs/preflight-last.json; fi
  ```

  The line is silent when preflight exits 0 without a heartbeat warning. It prints the JSON document when preflight
  fails (exit 1) **or** when the scheduler has stopped — a stopped scheduler is only a warning (exit 0), which is why
  the second clause looks for `scheduler heartbeat` in the output (every heartbeat warning contains those words).
  `--json` prints the document on every run, so never simply drop the redirection: that would mail every hour.

  **The alert itself is not in the tree.** Output of a cron job becomes an e-mail only when the host sends cron output
  to an address (an option of the host's cron page, or a `MAILTO=` line where the crontab is editable) — confirm the
  plan offers it. Otherwise the owner chooses another way to be told (nothing is assumed here); that choice and the
  alert recipient are open monitoring rows of [GoLive_Checklist.md](GoLive_Checklist.md) (section 15). Right after a
  release the heartbeat warning is expected for a minute or two (section 4.1); an hourly run rarely coincides with it.
- **Scheduler:** the heartbeat (section 3.10) through preflight.
- **Failed jobs:** `php artisan queue:failed` (OPERATIONS.md).

---

## 10. Security reminders

- **Invite-only** (D-11): no registration page; users are invited from *System → Users*; the first super admin comes
  from `app:onboard`.
- **MFA** is offered to everybody and required of nobody (D-11); ask administrators to enable it.
- **Passwords:** at least 12 characters, mixed case, a number, not found in known breaches (`Password::defaults()`).
  Sessions expire after 120 idle minutes; cookies are https-only.
- `APP_DEBUG=false`, https `APP_URL`, `SESSION_SECURE_COOKIE=true` — enforced by preflight.
- `.env` mode `600` (or `640` with PHP's group, section 3.7), never in git, never in the web root; `APP_KEY` is never shared or rotated casually.
- Only `public/` is served (section 3.5). Attachments and exports are on the private disk and downloaded only through
  authorised routes.
- Trust proxies narrowly (`CRM_TRUSTED_PROXIES`, section 3.6).
- `app:demo-data` refuses to run with `APP_ENV=production`; never point a staging copy at the production database.
- A disabled or pending user is refused on their next request, even from a page that is already open.

---

## 11. The deploy workflow and its secrets

`.github/workflows/deploy.yml` runs only by hand (*Actions → Deploy → Run workflow*, input: a release note). It is safe
to leave unused: without the secrets the `build` job produces the archive and the `deploy` job skips every step with a
notice. **Only a run dispatched from `master` deploys** (production, A-14): from `develop` or any other branch the
`build` job still produces the archive, and the `deploy` job skips every step with a warning naming the ref.

| Secret | Required for the deploy job | Value |
|---|---|---|
| `DEPLOY_SSH_HOST` | yes | The SSH host name hPanel shows for the account. |
| `DEPLOY_SSH_USER` | yes | The SSH user. |
| `DEPLOY_SSH_KEY` | yes | A private key created for deployments only; its public half added to the account's authorised SSH keys. |
| `DEPLOY_PATH` | yes | Absolute path of the release-folders root on the host (letters, digits, `.`, `_`, `-`, `/` only), outside the web root. |
| `DEPLOY_SSH_PORT` | no (default `22`) | The SSH port hPanel shows — shared hosts often use a non-standard one. |
| `DEPLOY_SSH_KNOWN_HOSTS` | **yes, once the first four are set** | The host's `known_hosts` line (`ssh-keyscan -p <port> <host>` from a trusted network, fingerprint checked against the host's panel; for a port other than 22 the entry starts with `[host]:port`). The job fails closed: without it, or when it has no entry for `DEPLOY_SSH_HOST`, the deploy stops before connecting — it never trusts a host key learned during the run. |
| `DEPLOY_PHP_BINARY` | no (default `php`) | Absolute path of PHP 8.3 on the host (section 3.10). The job refuses a PHP older than 8.3. |

The deploy job runs only when the first four secrets are all set and the run is on `master`; the check happens in a
step, and no secret is printed. The third-party actions (`actions/*`, `shivammathur/setup-php`) are pinned by major
tag, as in `ci.yml`; pin them by commit SHA if the organisation's policy requires it.

**Prepare the host once** (SSH):

```bash
mkdir -p DEPLOY_PATH/shared && cd DEPLOY_PATH/shared
# write .env (section 3.6; APP_KEY via `php artisan key:generate --show` on any machine), then:
chmod 600 .env        # PHP running as the account's own user; otherwise 640 with PHP's group (section 3.7)
```

`DEPLOY_PATH/current` must not exist as a real directory (the job refuses; move it aside). The deploy workflow needs
the web root to **follow** `current`: either the domain's document root is `DEPLOY_PATH/current/public` (section 3.5,
preferred) or `<web root>` is a symlink to it (section 3.5, fallback 1). **Fallback 2 does not work with the workflow**:
its `<web root>` holds a copy of the public files that the job never refreshes, so after the first unattended release
the new manifest points at hashed `build/` files that are not in the web root and the panel's CSS and JavaScript answer
404. Fallback 2 is for the single-directory layout deployed by hand (section 4.1). If a host leaves no other choice, the
copy becomes a mandatory step after every workflow run, done by hand over SSH before anyone uses the site:

```bash
rsync -a --exclude=index.php DEPLOY_PATH/current/public/ <web root>/
```

and those runs are then not unattended. Point the cron line at `cd DEPLOY_PATH/current`. The job creates
`shared/storage` on its first run.

**First run through the workflow.** The database has no super admin yet, so the first run stops at `app:preflight`
("No active super admin") and does not switch `current`. Finish by hand over SSH:

```bash
cd DEPLOY_PATH/releases/<release>
<php> artisan app:onboard          # the PHP 8.3 binary of DEPLOY_PHP_BINARY; hidden password prompt (section 3.9)
<php> artisan app:preflight
cd DEPLOY_PATH && ln -sfn releases/<release> current.next && mv -Tf current.next current
```

Every later run is unattended (with a document root or fallback 1 as above). What the job does on the host is listed in section 4.2; a failure leaves `current`
unchanged and, when a release was live, the site in maintenance mode — read the job log, fix, and either rerun the
workflow or follow section 5 before `php artisan up`.

---

## 12. `.env` reference

Every key in `.env.example`, with its production value. "Keep" means: leave the value `.env.example` ships.
`tests/Feature/System/DeploymentDocsTest.php` fails when a key of `.env.example` is missing here.

**Application**

| Key | Production |
|---|---|
| `APP_NAME` | The product name shown in the browser title and mail. |
| `APP_ENV` | `production`. |
| `APP_KEY` | Generated once on the server (`php artisan key:generate`). |
| `APP_PREVIOUS_KEYS` | Empty; during a key rotation, the old key(s), comma-separated. |
| `APP_DEBUG` | `false`. |
| `APP_URL` | `https://...` of the site. |
| `APP_LOCALE` | `ar` (D-5). |
| `APP_FALLBACK_LOCALE` | `en` (D-5). |
| `APP_FAKER_LOCALE` | Keep (`ar_SA`; factories only). |
| `APP_TIMEZONE` | `Asia/Riyadh` — storage timezone, never changed once data exists. |
| `APP_MAINTENANCE_DRIVER` | `file` (one server; shared `storage` in the release-folders layout). |
| `APP_MAINTENANCE_STORE` | Keep (used only by the `cache` maintenance driver). |
| `BCRYPT_ROUNDS` | `12`. |

**First super admin** — `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`: blank; filled only temporarily for a
non-interactive `app:onboard` (section 3.9).

**Authentication** — `AUTH_GUARD` (`web`), `AUTH_PASSWORD_BROKER` (`users`), `AUTH_MODEL` (`App\Models\User`),
`AUTH_PASSWORD_RESET_TOKEN_TABLE` (`password_reset_tokens`), `AUTH_PASSWORD_TIMEOUT` (`10800`): keep.

**Logging**

| Key | Production |
|---|---|
| `LOG_CHANNEL` | `stack`. |
| `LOG_STACK` | `daily`. |
| `LOG_LEVEL` | `warning`. |
| `LOG_DAILY_DAYS` | `14`. |
| `LOG_DEPRECATIONS_CHANNEL` | Keep (`null`). |
| `LOG_DEPRECATIONS_TRACE` | Keep (`false`). |

Unused channels, keep as shipped: `LOG_SLACK_WEBHOOK_URL`, `LOG_SLACK_USERNAME`, `LOG_SLACK_EMOJI`,
`LOG_PAPERTRAIL_HANDLER`, `PAPERTRAIL_URL`, `PAPERTRAIL_PORT`, `LOG_STDERR_FORMATTER`, `LOG_SYSLOG_FACILITY`.

**Audit ledger** — `ACTIVITY_LOGGER_ENABLED` (`true`, never switch off), `ACTIVITY_LOGGER_TABLE_NAME`
(`activity_log`), `ACTIVITY_LOGGER_DB_CONNECTION` (`null` = the default connection): keep.

**Database**

| Key | Production |
|---|---|
| `DB_CONNECTION` | `mysql`, or `mariadb` when the host confirmed MariaDB. |
| `DB_URL` | Keep (`null`); the fields below are used. |
| `DB_HOST` | As listed in hPanel. |
| `DB_PORT` | As listed in hPanel. |
| `DB_SOCKET` | Empty unless the host requires a socket. |
| `DB_DATABASE` | As created in hPanel. |
| `DB_USERNAME` | As created in hPanel. |
| `DB_PASSWORD` | As created in hPanel. |
| `DB_CHARSET` | `utf8mb4` (Arabic). |
| `DB_COLLATION` | `utf8mb4_unicode_ci`. |
| `MYSQL_ATTR_SSL_CA` | `null` unless the host requires TLS to the database. |
| `DB_FOREIGN_KEYS`, `DB_SSLMODE` | Keep (SQLite and PostgreSQL options, unused). |

**Session**

| Key | Production |
|---|---|
| `SESSION_DRIVER` | `file` or `database`. |
| `SESSION_LIFETIME` | `120` (D-11). |
| `SESSION_EXPIRE_ON_CLOSE` | `false`. |
| `SESSION_ENCRYPT` | `false`. |
| `SESSION_CONNECTION` | `null` (database driver: the default connection). |
| `SESSION_TABLE` | `sessions`. |
| `SESSION_STORE` | `null`. |
| `SESSION_COOKIE` | `crm-session`. |
| `SESSION_PATH` | `/`. |
| `SESSION_DOMAIN` | `null` (the request host). |
| `SESSION_SECURE_COOKIE` | `true` (D-11; preflight fails otherwise). |
| `SESSION_HTTP_ONLY` | `true`. |
| `SESSION_SAME_SITE` | `lax`. |
| `SESSION_PARTITIONED_COOKIE` | `false`. |

**Cache**

| Key | Production |
|---|---|
| `CACHE_STORE` | `file` or `database`, never `array`. |
| `CACHE_PREFIX` | `crm-cache-`. |
| `CACHE_STORAGE_DISK`, `CACHE_STORAGE_PATH` | Keep (`null`, `framework/cache/data`). |
| `DB_CACHE_CONNECTION`, `DB_CACHE_TABLE`, `DB_CACHE_LOCK_CONNECTION`, `DB_CACHE_LOCK_TABLE` | Keep (`null`, `cache`, `null`, `null`). |

**Queue**

| Key | Production |
|---|---|
| `QUEUE_CONNECTION` | `database`, never `sync`. |
| `DB_QUEUE_CONNECTION` | `null`. |
| `DB_QUEUE_TABLE` | `jobs`. |
| `DB_QUEUE` | `default`. |
| `DB_QUEUE_RETRY_AFTER` | `90` (must stay above the longest job timeout, 45 seconds). |
| `QUEUE_FAILED_DRIVER` | `database-uuids`. |

Services the CRM does not use on shared hosting (D-1), keep as shipped: `MEMCACHED_PERSISTENT_ID`,
`MEMCACHED_USERNAME`, `MEMCACHED_PASSWORD`, `MEMCACHED_HOST`, `MEMCACHED_PORT`, `REDIS_CLIENT`, `REDIS_CLUSTER`,
`REDIS_PREFIX`, `REDIS_PERSISTENT`, `REDIS_URL`, `REDIS_HOST`, `REDIS_USERNAME`, `REDIS_PASSWORD`, `REDIS_PORT`,
`REDIS_DB`, `REDIS_CACHE_DB`, `REDIS_MAX_RETRIES`, `REDIS_BACKOFF_ALGORITHM`, `REDIS_BACKOFF_BASE`,
`REDIS_BACKOFF_CAP`, `REDIS_CACHE_CONNECTION`, `REDIS_CACHE_LOCK_CONNECTION`, `REDIS_QUEUE_CONNECTION`,
`REDIS_QUEUE`, `REDIS_QUEUE_RETRY_AFTER`, `DYNAMODB_CACHE_TABLE`, `DYNAMODB_ENDPOINT`, `BEANSTALKD_QUEUE_HOST`,
`BEANSTALKD_QUEUE`, `BEANSTALKD_QUEUE_RETRY_AFTER`, `SQS_PREFIX`, `SQS_QUEUE`, `SQS_SUFFIX`.
`BROADCAST_CONNECTION`: keep (`log`; no broadcasting).

**Mail** (D-10)

| Key | Production |
|---|---|
| `MAIL_MAILER` | `log` until SMTP exists (nothing delivered, preflight warns), then `smtp`. |
| `MAIL_SCHEME` | `null` (STARTTLS negotiated), or `smtps` for implicit TLS, as the mail provider says. |
| `MAIL_URL` | Keep (`null`). |
| `MAIL_HOST` | The SMTP host of the organisation's mail account. |
| `MAIL_PORT` | The SMTP port of that account. |
| `MAIL_USERNAME` | The SMTP user. |
| `MAIL_PASSWORD` | The SMTP password. |
| `MAIL_EHLO_DOMAIN` | The site's host name. |
| `MAIL_LOG_CHANNEL` | Keep (`null`). |
| `MAIL_SENDMAIL_PATH` | Keep (unused sendmail mailer). |
| `MAIL_FROM_ADDRESS` | An address on the organisation's domain. |
| `MAIL_FROM_NAME` | Keep (`${APP_NAME}`) or the organisation's name. |

Unused providers, keep `null`: `POSTMARK_API_KEY`, `RESEND_API_KEY`, `SLACK_BOT_USER_OAUTH_TOKEN`,
`SLACK_BOT_USER_DEFAULT_CHANNEL`.

**Files** — `FILESYSTEM_DISK`: `local` (private disk, A-6). Unused S3 settings, keep as shipped:
`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_URL`, `AWS_ENDPOINT`,
`AWS_USE_PATH_STYLE_ENDPOINT`.

**CRM** (`config/crm.php`)

| Key | Production |
|---|---|
| `CRM_CURRENCY` | `SAR` — seeded into General settings on the first seed; later changes in the panel. |
| `CRM_TIMEZONE` | `Asia/Riyadh` — seeded into General settings on the first seed; later changes in the panel. |
| `CRM_LEAD_STALE_DAYS` | `14`. |
| `CRM_AUDIT_RETENTION_DAYS` | `730` (D-13). |
| `CRM_ATTACHMENT_MAX_KB` | `10240`, within the PHP upload limits (section 6). |
| `CRM_TRUSTED_PROXIES` | The host's proxy addresses, or `*` only when PHP is reachable solely through the proxy. Left unset behind a TLS-terminating proxy, invitation and password-reset links answer 403 and the sign-in, import and export throttles are shared by everybody (section 3.6, with the `curl` check). |

**Front-end build** — `VITE_APP_NAME`: keep (`${APP_NAME}`; read at build time in CI).
