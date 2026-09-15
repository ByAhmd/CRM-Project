# CRM — Go-Live Checklist

Exit criterion of plan step 12 ([ARCHITECTURE_PLAN.md](ARCHITECTURE_PLAN.md) section 5). Every row names its evidence: a
test file (run on MySQL 8.4 unless the row says otherwise), a command and its result, or `manual walk`. A row is **done**
only when that evidence exists in the tree or in the step-12 package reports; everything nobody could verify stays
**open** with an owner. Step 13 (production readiness) closes the open engineer rows; the owner rows need the owner.

- **Owner** — `engineer`: the development side (the engineer and the engineer's agents). `owner`: the product owner,
  for anything that needs a signed-in human, the production host or a written confirmation.
- **Suite baseline** — the merged suite had 1356 tests green before the completeness critic added
  `tests/Feature/QualityPass/Completeness/CompletenessProbeTest.php`. After the code and migration packages it ran
  1379 tests on MySQL 8.4.11 and on MariaDB 10.4.32 with the same four failures, all documentation probes that this
  checklist and the documentation package close. After the documentation package the full suite ran 1379 tests,
  1379 passed, on MySQL 8.4.11 (`crm_testing_c`, 2026-09-15). After the REFIX package (import reassignment through
  `RecordAssignmentService` and its 11 tests) the full suite ran 1390 tests, 1390 passed, on MySQL 8.4.11 (`crm_testing_a`,
  2026-09-15).

## 1. Code quality gates

| Item | Evidence | Date | Result | Owner |
|---|---|---|---|---|
| `declare(strict_types=1)` and `final` on every project class | `tests/Feature/Qa/StructuralGuardsTest.php` (`every_project_php_file_declares_strict_types`, `every_named_class_under_app_database_and_tests_is_final_unless_it_is_abstract`), `tests/Feature/QualityPass/Tests/StructuralGuardGapsProbeTest.php` | 2026-09-15 | done | engineer |
| Every policy defines the bulk abilities and refuses permanent deletion | `StructuralGuardsTest::every_policy_defines_the_bulk_abilities_and_refuses_permanent_deletion` | 2026-09-15 | done | engineer |
| Every table declares a default sort; no hardcoded Filament label; importer example rows carry no literal text | `StructuralGuardsTest` (`every_table_declares_a_default_sort`, `no_filament_class_carries_a_hardcoded_user_facing_label`, `->example()` scanner), `CompletenessProbeTest::importer_example_rows_carry_no_hardcoded_text` | 2026-09-15 | done | engineer |
| Models with business meaning log an explicit whitelist; fixture helpers are not copied into test classes | `StructuralGuardGapsProbeTest` (`every_model_with_business_meaning_logs_an_explicit_whitelist`, `no_fixture_helper_is_copied_into_three_or_more_test_classes`) | 2026-09-15 | done | engineer |
| Pint clean over the whole tree | `vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}`, exit 0 (merged tree after the code, migration, documentation and REFIX packages) | 2026-09-15 | done | engineer |
| PHPStan level 5 clean over the whole tree, no baseline | `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` → `{"tool":"phpstan","result":"passed","errors":0}`, exit 0 (same merged tree) | 2026-09-15 | done | engineer |
| The three `composer check` gates green on the same merged tree (Pint, PHPStan, full PHPUnit suite) | the two rows above plus `vendor/bin/phpunit` on `crm_testing_a` → 1390 tests, 1390 passed, 11 339 assertions, exit 0 (after the REFIX package; 1379 / 11 254 on `crm_testing_c` before it) | 2026-09-15 | done | engineer |
| CI runs lint, analyse, asset build and tests on PHP 8.3 and MySQL 8.4; PHPUnit fails on warnings, risky tests and deprecations | `.github/workflows/ci.yml`; `tests/Feature/QualityPass/Tests/ContinuousIntegrationParityProbeTest.php` | 2026-09-15 | done | engineer |
| CI green on GitHub for the step-12 commit | GitHub Actions run on `master` after the push | — | open | engineer |

## 2. Authorisation matrix

| Item | Evidence | Date | Result | Owner |
|---|---|---|---|---|
| Seeded roles equal `RolePermissionMatrix`; the catalogue has no drift | `tests/Feature/Access/RoleSeedingTest.php`, `tests/Feature/Access/PermissionMatrixTest.php` | 2026-09-15 | done | engineer |
| Every panel page answers each of the six seeded roles as the matrix says | `tests/Feature/QualityPass/Authorisation/RoleMatrixWalkProbeTest.php` | 2026-09-14 | done | engineer |
| Every verb of every owned entity has a positive and a negative answer per role | `tests/Feature/Access/OwnedPolicyMatrixTest.php`, `tests/Feature/QualityPass/Tests/OwnedPolicyVerbMatrixProbeTest.php` | 2026-09-14 | done | engineer |
| Own / team / all scope on list, edit, bulk and reassignment paths; out-of-scope edit pages answer 404 | `tests/Feature/Access/RecordVisibilityResolverTest.php`, `tests/Feature/QualityPass/Tests/OwnedEntityPanelScopeProbeTest.php`, `tests/Feature/QualityPass/Authorisation/RecordScopeLeakProbeTest.php` | 2026-09-14 | done | engineer |
| Pickers, relation panels and duplicate warnings never disclose records outside the actor's reach | `tests/Feature/QualityPass/Security/RelationPickerScopeProbeTest.php`, `DuplicateWarningScopeProbeTest.php`, `tests/Feature/QualityPass/Authorisation/DisclosureProbeTest.php` | 2026-09-14 | done | engineer |
| Import / export history and Filament's download routes enforce owner and all-level scope and account status | `tests/Feature/QualityPass/Authorisation/ImportExportScopeProbeTest.php`, `tests/Feature/QualityPass/Security/ExportHistoryScopeProbeTest.php`, `InactiveUserDownloadsProbeTest.php` | 2026-09-14 | done | engineer |
| Workflows cannot be bypassed: qualification note on create, trashed records frozen, assignment only to active users, `account.set_type` gate | `tests/Feature/QualityPass/Authorisation/WorkflowReachProbeTest.php`, `tests/Feature/QualityPass/Security/WorkflowBypassOnCreateProbeTest.php`, `tests/Feature/QualityPass/Schema/QualificationOnCreateProbeTest.php` | 2026-09-14 | done | engineer |
| User administration guards: no self-promotion, no super admin invite or demotion without `roles.manage`, last active super admin kept, nobody disables their own account | `tests/Feature/QualityPass/Authorisation/UserAdministrationProbeTest.php`, `tests/Feature/QualityPass/Security/UserAdministrationGuardsProbeTest.php`, `tests/Feature/Access/RoleServiceTest.php` | 2026-09-14 | done | engineer |
| `docs/PERMISSIONS.md` role rows match `RolePermissionMatrix` | Documentation package cross-check of every role row against `RolePermissionMatrix` (support gained `reports`; manager, rep, support and read-only rows corrected); REFIX package: the manager and rep rows now say that restoring a task is granted with `task.delete` (`TaskPolicy::restore()`; `OwnedPolicyMatrixTest` "Restoring a task is granted with task.delete"), where they had said "no restore" | 2026-09-15 | done | engineer |
| An import row that changes the owner of an existing record goes through `RecordAssignmentService` (`{entity}.assigned` audit event with the importer as causer, notification to the new owner), needs the record's `assign` verb, and a refused target rolls the whole row back; a new record created for another owner is a creation (A-20) | `tests/Feature/ImportExport/ImportReassignmentTest.php` (11 tests: the four importers reassign with audit and notification, the four leave an unchanged owner alone, creation is not a reassignment, `update` without `assign` cannot take a record over, a service refusal rolls the row back to its savepoint) | 2026-09-15 | done | engineer |

## 3. Security

| Item | Evidence | Date | Result | Owner |
|---|---|---|---|---|
| Security headers on every response (nosniff, frame deny, referrer policy, permissions policy, HSTS on https) | `tests/Feature/System/ProductionWiringTest.php::every_response_carries_the_security_headers` | 2026-09-15 | done | engineer |
| No registration route; password reset throttled; login locks out after five failures | `ProductionWiringTest`, `tests/Feature/QualityPass/Tests/SecurityPlanWithoutTestsProbeTest.php` | 2026-09-15 | done | engineer |
| MFA offered to everyone and required of no one (D-11) | `ProductionWiringTest::multi_factor_authentication_is_offered_to_everyone_and_required_of_no_one` | 2026-09-15 | done | engineer |
| Spreadsheet formula injection neutralised in every export, report export and failed-rows CSV | `tests/Feature/QualityPass/Security/SpreadsheetFormulaInjectionProbeTest.php` | 2026-09-14 | done | engineer |
| Import and export actions rate limited; kanban "load more" capped; calendar range capped at 500 entries | `SecurityPlanWithoutTestsProbeTest::the_import_and_export_actions_are_rate_limited`, `tests/Feature/QualityPass/Security/DealBoardRenderBoundsProbeTest.php`, `CompletenessProbeTest::the_calendar_feed_caps_the_number_of_events_one_range_returns`, `tests/Feature/Calendar/CalendarFeedTest.php` | 2026-09-15 | done | engineer |
| Attachments: private disk, server-sniffed MIME allowlist, authorised download | `tests/Feature/Attachments/AttachmentStorageTest.php`, `AttachmentDownloadTest.php` | 2026-09-15 | done | engineer |
| Friendly translated error pages exist for 403, 404, 419, 429, 500, 503 (`resources/views/errors`); the 404 renders in the default Arabic locale | `tests/Feature/QualityPass/Security/ErrorPagesProbeTest.php` | 2026-09-14 | done | engineer |
| Error pages with `APP_DEBUG=false` on staging: 403, 404, 419, 429, 500, 503 show the friendly page and no stack trace | manual walk on staging | — | open | engineer |
| HTTPS detection behind the host's proxy: `request()->isSecure()`, signed URLs and secure cookies behave correctly; trusted proxies decision recorded for step 13 (no `trustProxies` configuration exists today; `URL::forceScheme('https')` in production only) | manual check on the host + decision in `docs/DECISIONS.md` | — | open | engineer |
| A user disabled (or set back to pending) while a panel page is still open in their browser cannot keep acting through that page's Livewire requests. Filament registers its `Authenticate` middleware as Livewire persistent middleware for every panel (`FilamentServiceProvider`), so `User::canAccessPanel()` runs again on each `/livewire/update` request; the panel's own `isPersistent` flag is therefore not needed. The tests replay the real update request from an open lead list: 200 while active, 403 once disabled or pending | `tests/Feature/Access/OpenPageAfterStatusChangeTest.php` (`the_panel_authentication_middleware_runs_on_every_livewire_request`, `a_user_disabled_while_a_page_is_open_is_refused_on_the_next_request_from_it`, `a_user_set_back_to_pending_while_a_page_is_open_is_refused_on_the_next_request_from_it`) | 2026-09-15 | done | engineer |
| libmagic on the host sniffs the allowed types (pdf, doc, docx, xls, xlsx, csv, txt, png, jpg, jpeg, webp) as the local fileinfo does | upload one file of each type on the host | — | open | engineer |
| Production configuration refuses to go live when dangerous | `tests/Feature/System/PreflightCommandTest.php` (`a_dangerous_production_configuration_refuses_to_go_live`, `a_correct_production_configuration_passes`) | 2026-09-15 | done | engineer |

## 4. Localisation and RTL

| Item | Evidence | Date | Result | Owner |
|---|---|---|---|---|
| `lang/ar` and `lang/en` carry identical keys; no Arabic in English files; every used key defined, no unused key | `tests/Feature/Localization/TranslationParityTest.php`, `LangKeyUsageTest.php` | 2026-09-15 | done | engineer |
| Arabic default, English fallback; stored user locale wins over the browser; direction flips | `tests/Feature/Localization/DefaultLocaleTest.php`, `tests/Feature/System/PanelSmokeTest.php` | 2026-09-15 | done | engineer |
| Filament vendor keys resolve readably in Arabic | `tests/Feature/Localization/VendorTranslationFallbackTest.php` | 2026-09-15 | done | engineer |
| Every navigation page and resource index / create / view / edit page answers 200 with the locale's `lang` and `dir` and no raw key, in both locales | `tests/Feature/QualityPass/Localisation/RenderMatrixTest.php` | 2026-09-14 | done | engineer |
| Arabic wording: one term per concept, guillemets, one digit system, plural forms through `trans_choice` | `tests/Feature/QualityPass/Localisation/ArabicWordingTest.php` | 2026-09-14 | done | engineer |
| Mail in Arabic has no English framework lines and reads RTL | `tests/Feature/QualityPass/Localisation/MailLocalisationTest.php` | 2026-09-14 | done | engineer |
| Date-times display in the organisation timezone; report buckets fold in that timezone | `tests/Feature/QualityPass/Localisation/OrganisationTimezoneDisplayTest.php`, `tests/Feature/QualityPass/Tests/ReportTimezoneGuardProbeTest.php` | 2026-09-14 | done | engineer |

## 5. Theme

| Item | Evidence | Date | Result | Owner |
|---|---|---|---|---|
| Theme uses logical properties only, no hex colour outside the token blocks, dark-mode grays in the format Filament 5 consumes, direction-correct arrows, no hardcoded list separators | `tests/Feature/QualityPass/Localisation/ThemeAndDirectionTest.php` | 2026-09-14 | done | engineer |
| Rendered light and dark appearance of every page | covered by the visual walk in section 13 | — | open | owner |

## 6. Database and migrations (both engines)

| Item | Evidence | Date | Result | Owner |
|---|---|---|---|---|
| Schema contract: CHECK per code enum, FK delete rules, indexes on owner / assignee / status, designed unique keys, business moments are DATETIME | `tests/Feature/Database/SchemaContractTest.php`, `tests/Feature/QualityPass/Tests/SchemaConstraintGuardProbeTest.php`, `tests/Feature/QualityPass/Schema/EnumCheckCoverageProbeTest.php`, `DateTimeColumnTypeProbeTest.php` | 2026-09-15 | done | engineer |
| Column bounds, scoring-rule uniqueness and scoring-rule foreign keys hold at the database | `tests/Feature/QualityPass/Schema/ColumnBoundsAndUniquenessProbeTest.php`; `information_schema` on `crm_testing_c` after `migrate:fresh` shows `lead_scoring_rules_rule_unique (kind, reference_key, field_key, within_days_key)` and RESTRICT keys on `lead_source_id` / `lead_status_id` | 2026-09-15 | done | engineer |
| Lookups in use, soft-deleted namesakes, retired references and merges never crash or orphan rows | `tests/Feature/QualityPass/Schema/LookupInUseDeletionProbeTest.php`, `SoftDeletedNamesakeUniquenessProbeTest.php`, `RetiredReferencesOnEditProbeTest.php`, `MergeOrphansChildrenProbeTest.php` | 2026-09-14 | done | engineer |
| Round trip on MySQL 8.4.11 (`crm_testing_b`): `migrate:fresh --seed --force`, `migrate:rollback --step=500 --force`, `migrate --force`, `db:seed --force` twice, `app:preflight` | every step exit 0; preflight "OK (local environment, 1 warning)" — migration package report | 2026-09-15 | done | engineer |
| Round trip on MariaDB 10.4.32 (`crm_testing_maria`): the same six commands | every step exit 0 after the engine-aware fix to `2026_09_14_400011` — migration package report | 2026-09-15 | done | engineer |
| Full suite on MariaDB 10.4.32 shows no engine difference | 1379 tests, 1375 passed, the same 4 documentation-probe failures as MySQL — migration package report | 2026-09-15 | done | engineer |
| DATETIME conversions refuse to roll back while a value is outside the TIMESTAMP range | `tests/Feature/Database/TimestampRollbackGuardTest.php` (13 tests, both engines) | 2026-09-15 | done | engineer |
| Fresh install leaves a usable CRM; reference seed is idempotent | `tests/Feature/QualityPass/Schema/FreshInstallReferenceDataProbeTest.php`, `tests/Feature/System/OnboardCommandTest.php` | 2026-09-15 | done | engineer |
| Production database engine and exact version confirmed in writing (D-1); MariaDB behaviour rechecked on that version if it is not 10.4 | written confirmation from the host, recorded in `docs/DECISIONS.md` | — | open | owner |

## 7. Performance

| Item | Evidence | Date | Result | Owner |
|---|---|---|---|---|
| Lazy loading prevented for the whole suite, so any N+1 fails its test | `tests/TestCase.php`; `StructuralGuardsTest::lazy_loading_is_prevented_while_the_suite_runs` | 2026-09-15 | done | engineer |
| No per-row queries on list pages, record pages, relation managers, dashboard, reports, board and calendar | `tests/Feature/QualityPass/Performance/ListPagesQueryCountTest.php`, `RecordPageQueryCountTest.php`, `RelationManagersQueryCountTest.php`, `DashboardAndReportQueryCountTest.php`, `BoardAndCalendarQueryCountTest.php` | 2026-09-15 | done | engineer |
| Export, import and rescore costs are bounded; settings and custom-field definitions read once per request | `tests/Feature/QualityPass/Performance/ExportImportQueryCountTest.php`, `SettingsMemoisationTest.php` | 2026-09-14 | done | engineer |
| Hot filter and sort columns lead an index; date filters compare the raw column | `tests/Feature/QualityPass/Performance/IndexCoverageTest.php` | 2026-09-14 | done | engineer |
| `EXPLAIN` of list, dashboard, report, board and calendar queries on seeded production-sized data, and page timings on the host | `EXPLAIN` output and timings recorded here | — | open | engineer |

## 8. Test suite

| Item | Evidence | Date | Result | Owner |
|---|---|---|---|---|
| Quality-pass probes kept as permanent regression tests (`tests/Feature/QualityPass/*`, 46 test files) | `CLAUDE.md` section 3, Tests | 2026-09-15 | done | engineer |
| Nine probe files were edited after the audits: `tests/Feature/QualityPass/Authorisation/UserAdministrationProbeTest.php`, `tests/Feature/QualityPass/Security/UserAdministrationGuardsProbeTest.php`, `tests/Feature/QualityPass/Authorisation/ImportExportScopeProbeTest.php`, `tests/Feature/QualityPass/Security/ExportHistoryScopeProbeTest.php`, `tests/Feature/QualityPass/Tests/OwnedPolicyVerbMatrixProbeTest.php` (the D-13 export grant; the edit is noted in the file), `tests/Feature/QualityPass/Schema/EnumCheckCoverageProbeTest.php`, `tests/Feature/QualityPass/Schema/LookupInUseDeletionProbeTest.php`, `tests/Feature/QualityPass/Localisation/ArabicWordingTest.php` (all eight last modified 2026-09-14 12:05–12:21, after the audit run of 11:44) and `tests/Feature/QualityPass/Authorisation/RoleMatrixWalkProbeTest.php` (modified 2026-09-15 10:51, after the 10:21 full run in which it failed on matrix mismatches). The package verifier reports that confirmed them are not kept in the tree, so the REFIX package re-checked each file against the recorded failures of those runs: all 35 methods that failed on a defect still exist under the same name; every custom failure message they raised is still asserted; the rest still assert the same framework outcome (`assertForbidden()`, the "crashed instead of refusing" failure, `assertDatabaseMissing`, `assertNotSoftDeleted`); and all nine files pass in the full suite below | REFIX package report (2026-09-15): per-method check of the nine files against the audit-run failure log | 2026-09-15 | done | engineer |
| The completeness probe `importer_example_rows_carry_no_hardcoded_text` was narrowed to skip machine values (enum keys, ISO codes, e-mail addresses, URLs) the importers parse literally; it still fails on the original literal text, checked against the HEAD `AccountImporter` | code package report | 2026-09-15 | done | engineer |
| The seven completeness probes pass | `vendor/bin/phpunit tests/Feature/QualityPass/Completeness` on `crm_testing_c`; again inside the full `crm_testing_a` run after the REFIX package | 2026-09-15 | done | engineer |
| Full suite green on MySQL 8.4 after all step-12 packages are merged | `DB_DATABASE=crm_testing_a vendor/bin/phpunit` → `{"tool":"phpunit","result":"passed","tests":1390,"passed":1390,"assertions":11339}`, exit 0 (REFIX package; the run before it on `crm_testing_c` was 1379 / 1379) | 2026-09-15 | done | engineer |
| Full suite rerun on MariaDB after the documentation and REFIX packages (the MariaDB run predates both; the REFIX package changed the importers' owner handling and added `ImportReassignmentTest`) | `vendor/bin/phpunit` against MariaDB 10.4 | — | open | engineer |

## 9. Configuration

| Item | Evidence | Date | Result | Owner |
|---|---|---|---|---|
| `app:preflight` refuses a blank key, debug, non-https URL, insecure cookie and sync queue in production, pending migrations and missing reference rows; warns on the log mailer | `tests/Feature/System/PreflightCommandTest.php` | 2026-09-15 | done | engineer |
| Locale, timezone and session defaults are the decided ones (ar, Asia/Riyadh, 120 minutes) | `ProductionWiringTest::the_locale_and_timezone_defaults_are_the_decided_ones`; `.env.example` | 2026-09-15 | done | engineer |
| Production `.env` set on the server only (`APP_ENV=production`, `APP_DEBUG=false`, https `APP_URL`, `SESSION_SECURE_COOKIE=true`, `QUEUE_CONNECTION=database`, `LOG_LEVEL=warning`) and `app:preflight` exit 0 there | `php artisan app:preflight` on the host | — | open | engineer |
| `.env` reference and deployment runbook | `docs/DEPLOYMENT.md` (step 13) | — | open | engineer |

## 10. Scheduler and queue

| Item | Evidence | Date | Result | Owner |
|---|---|---|---|---|
| Every schedule entry registered once with its cadence and `onOneServer()` (queue drain, reminders, overdue, stale leads, `RescoreLeads` 03:00, `attachments:prune-temporary`, audit and failed-job retention, import/export prune, `reports:prune-downloads`) | `tests/Feature/Notifications/SchedulerWiringTest.php`, `ProductionWiringTest`; plan section 3.6; `CompletenessProbeTest::the_plan_names_every_scheduled_entry` | 2026-09-15 | done | engineer |
| `RescoreLeads` batch fits one queue drain (`$timeout = 45` under `--max-time=50` and `retry_after` 90) | `app/Jobs/RescoreLeads.php`; `ExportImportQueryCountTest::the_rescore_job_costs_at_most_one_query_per_lead` | 2026-09-15 | done | engineer |
| Real database-queue smoke run: an export and an import queued, drained by `queue:work --stop-when-empty`, files downloadable, failed rows readable, completion notices delivered | manual run with `QUEUE_CONNECTION=database` | — | open | engineer |
| Host cron runs `schedule:run` every minute and the drain completes inside the host's process limits | cron entry and one day of `schedule:run` logs on the host | — | open | engineer |

## 11. Mail

| Item | Evidence | Date | Result | Owner |
|---|---|---|---|---|
| Mail channel only with a real transport and the user's opt-in; invitation mail always | `tests/Feature/Notifications/NotificationChannelsTest.php`, `tests/Feature/Access/UserInvitationTest.php` | 2026-09-15 | done | engineer |
| Event notifications reach only recipients who can open the record and can sign in | `tests/Feature/QualityPass/Authorisation/DisclosureProbeTest.php`, `WorkflowReachProbeTest.php` | 2026-09-14 | done | engineer |
| Production SMTP credentials configured (D-10) | `.env` on the host; preflight without the mail warning | — | open | owner |
| Invitation, reset, notification and templated mail render correctly (Arabic RTL, English LTR, links, bidi) in real clients (Gmail web, Outlook, a phone client) once SMTP exists | manual walk | — | open | owner |

## 12. Backups

| Item | Evidence | Date | Result | Owner |
|---|---|---|---|---|
| Daily database backup on the host with a retention agreed with the owner (nothing in the tree provides one) | host backup setting | — | open | owner |
| Backup of the private storage disk (attachments, exports) | host backup setting | — | open | owner |
| One restore rehearsed into a scratch database and the app booted against it | manual restore | — | open | engineer |

## 13. Visual walks

| Item | Evidence | Date | Result | Owner |
|---|---|---|---|---|
| Authenticated visual walk: every navigation item, one create and one edit modal or page per resource, record view tabs, DealBoard and Calendar — in ar/rtl and en/ltr, light and dark, at desktop width and at 375 px; including FullCalendar digits under Arabic and the bidi of e-mail addresses and URLs inside Arabic sentences. Signing in is not something the engineer's agents may do | manual walk | — | open | owner |

## 14. Onboarding

| Item | Evidence | Date | Result | Owner |
|---|---|---|---|---|
| `app:onboard` seeds every reference row and creates the first active super admin; refuses a weak password and a second super admin; a re-run repairs without duplicating | `tests/Feature/System/OnboardCommandTest.php` | 2026-09-15 | done | engineer |
| Invitation flow: pending user sets a password through the signed reset link and becomes active | `tests/Feature/Access/InvitationPasswordResetTest.php`, `UserInvitationTest.php` | 2026-09-15 | done | engineer |
| First super admin created on production and the owner signs in, enables MFA and invites the team | manual on production | — | open | owner |

## 15. Monitoring

| Item | Evidence | Date | Result | Owner |
|---|---|---|---|---|
| Health endpoint `/up` answers | `tests/Feature/System/PanelSmokeTest.php::the_health_endpoint_answers` | 2026-09-15 | done | engineer |
| Audit ledger records sign-in events; retention 730 days pruned weekly | `tests/Feature/Audit/AuthActivityTest.php`, `SchedulerWiringTest::the_audit_ledger_and_the_failed_jobs_are_pruned_weekly_on_one_server`, `ProductionWiringTest::the_scheduler_prunes_the_audit_ledger_after_the_configured_retention` | 2026-09-15 | done | engineer |
| Uptime check against `/up` and an alert recipient chosen | external uptime monitor | — | open | owner |
| Production log level `warning`, log rotation on the host, and a weekly look at `failed_jobs` and the log | host configuration + runbook entry | — | open | engineer |
