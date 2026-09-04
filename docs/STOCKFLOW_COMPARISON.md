# Stockflow → CRM: engineering comparison and reuse classification

Reference project: `C:\Users\ahmed\Desktop\StockFlow-MainWork` (ZonKSA, formerly StockFlow), read-only.
Verified 2026-09-04 by eleven independent area readers, each adversarially checked against the code.
Business logic (inventory, purchasing, catalogue, orders, replenishment, AI) is **not** reused; only
engineering mechanics are.

## 1. Comparison table

| Area | Stockflow Approach | Proposed CRM Approach | Reuse/Adapt/Replace | Reason |
|---|---|---|---|---|
| Project Structure | Laravel app at repo root; `app/{Enums, Models/Concerns, Models/Scopes, Observers, Policies/Concerns, Services/<Domain>, Support/{Access,Filament,Database}, Exceptions/<Domain>, Jobs, Notifications, Listeners, Console/Commands, Filament/{Resources,Pages,Widgets,Concerns,Support,Auth,Platform}}`; `lang/{ar,en}/<module>.php`; `tests/Feature/<Domain>`; `docs/`, `tools/`, `scripts/` | Same skeleton with CRM domains; no `Filament/Platform`, no multi-panel support classes; add `Filament/{Imports,Exports}`, `Services/{Leads,Deals,Activities,Tasks,Attachments,Views,Statistics}` | **Reuse** | Proven layout that Larastan, Pint and the owner's habits assume |
| Filament Structure | Four panels sharing `ConfiguresWorkspacePanel`; explicit resource registry per panel; launcher; Resource → `Schemas/*Form`, `Tables/*Table`, `Pages/*`, `RelationManagers/*`; `NavigationGroup` enum with closure labels; label methods only; `runWorkflow()` helper on view pages | One `AdminPanelProvider` with the same presentation chain inline (`->darkMode()->themeSwitcher()->databaseNotifications()->font()->viteTheme()->navigationGroups()->userMenuItems()->spa()->globalSearchKeyBindings()->strictAuthorization()`), resource/page discovery guarded by a structural test; identical resource composition; `RunsWorkflowActions` trait | **Adapt** | Spec fixes one panel; keep the pieces that fix bilingual bugs and enforce structure; drop cross-panel plumbing |
| Models | `final`, `strict_types`, `#[Fillable]`/`#[Hidden]`/`#[ObservedBy]`, `casts()`, `@property` docblocks, relation generics; `BelongsToCompany` fail-closed tenant scope (User exempt); `HasLocalisedName`; workflow-guard static flag; number generators; bcmath `Decimal` | Same base contract; concerns `HasOwner`, `HasTags`, `HasAttachments`, `HasNotes`, `HasTasks`, `HasActivities`, `GuardsWorkflowFields` (scoped helper instead of a static bool); `HasLocalisedName` on lookups only; no tenant scope (pending Q2); `DECIMAL(14,2)` money | **Adapt** | Contract is enforced by Larastan; tenancy, stores and numbering are Stockflow domain |
| Services/Actions | `Services/<Domain>/*Workflow` (transaction + lock + transition table + exception + log row), `Reader`/`Row` DTOs, `Resolver`, `Presenter`, `Query`; translated domain exceptions; Filament pages only gate and call services | `LeadStatusWorkflow`, `LeadConversionWorkflow`, `DealStageWorkflow`, `TaskRecurrenceService`, `TimelineReader`/`TimelineEntry`, `RecordVisibilityResolver`, `SavedViewStore`, `AttachmentStorage`, `*Analytics`; shared action factories | **Reuse (shape) / Replace (domain)** | Keeps business logic testable and out of Filament |
| Authorization | Code `PermissionMatrix` (role × module → level) + `ChecksPermissionMatrix` policy trait + `StoreScopeResolver`; spatie roles/permissions seeded as a projection; Shield installed but dormant; `users.role` enum column | spatie roles/permissions as the store with typed `Permission` enum keys; defaults in `RolePermissionMatrix` seeded by `RolesAndPermissionsSeeder` + drift test; `ChecksPermissions` trait keeping Stockflow's explicit `*Any` methods and `forceDelete=false`; `RecordVisibilityResolver` (own/team/all); own Roles resource; `Gate::before` for `super_admin` | **Adapt** (pending Q3/Q4) | Spec requires roles/permissions managed in the admin panel; Stockflow's trait mechanics close Filament's allow-on-absent hole regardless of the permission source |
| Database | MySQL 8 tests; string + CHECK enums (inconsistent in later sprints); Sprint-1 migration rules (one table per file, docblocks) that drifted into bundles; triggers; append-only ledgers; `DECIMAL` sizing; tenant-prefixed uniques | One table per migration with docblock (guard test); `EnumCheck` helper (engine-aware); CHECK only on code enums; append-only via observers; owner/status indexes; `activity_log` index migration; `saved_views`, `tags/taggables`, `attachments`, Filament import/export tables | **Adapt** | Keep the integrity discipline, drop tenant/inventory specifics, add tooling so drift cannot recur |
| Localization | `lang/{ar,en}/<module>.php`, ar default / en fallback, parity + framework + vendor + default-locale tests, cookie-only language choice, enum labels split across files | Identical plumbing; enum labels only in `enums.php`; `users.locale` persisted from `LocaleChanged`; `contacts.preferred_locale`; JSON lang files forbidden; unused-key and literal-label scanner tests; default locale pending Q5 | **Reuse + extend** | Bilingual requirement is identical; add the two guards Stockflow lacks |
| RTL/LTR | Filament direction handling; logical CSS properties; one `[dir='rtl']` chevron rule + `unicode-bidi: isolate`; `dir="ltr"` on Latin values; five `[dir='ltr']` exceptions in page CSS | Same discipline with no `[dir='ltr']` exceptions; direction + labels passed to kanban/calendar JS as JSON; `firstDayOfWeek` from settings | **Reuse** | Zero-cost and version-proof |
| Themes | 4,640-line `theme.css` (mostly page-specific `sf-` CSS), generated OKLCH ramps in `config/brand-colors.php`, two-layer dark mode (`:root`/`.dark` tokens + `html.dark` gray re-anchor), Tajawal, measured AA fixes | New `theme.css` under ~400 lines: `@import`, `@source` (incl. language-switch views), `@theme`, `--crm-*` tokens, `.dark` redefinition, gray re-anchor, RTL rules, AA button fix; CRM palette via a copy of `tools/ramp.mjs`; `->themeSwitcher()->defaultThemeMode(System)`; Filament components instead of page CSS | **Adapt** | Structure proven; palette and page CSS are Stockflow brand |
| Notifications | `->databaseNotifications()->databaseNotificationsPolling('60s')`; Laravel notifications wrapping Filament's database envelope; queued alert, synchronous invitation | Same envelope + notification actions; assignment/task/deal/mention notifications; `notification_preferences`; mail channel only when a mailer is configured; polling 30 s | **Reuse** | Identical mechanism |
| Audit Logging | spatie activitylog; `LogsActivity` whitelist on `User` only; service events with `activity()->withProperties()`; `ActivityLogEvent` enum; read-only resource + presenter; 365-day retention; no append-only guard; policy `view` unused; JSON scoping unindexed; policy coupled to an inventory module | activitylog on every audited model; exhaustive `getDescriptionForEvent`; `<Domain>ActivityLogger` services; `ActivityLogAppendOnlyObserver`; own `audit.view` permission; indexes on subject/causer + `created_at`; `->authorize()` on details; auth listeners; retention from config (default 730 days) | **Adapt** | Audit logs are a spec requirement; fix the verified holes while reusing the shape |
| Testing | PHPUnit 12 attributes, real MySQL test DB, fixture trait, `Filament::setCurrentPanel`, Livewire idioms, per-role policy loops, isolation/drift/structural/translation/wiring tests; no time control; `TestCase` empty | Same suite shape with `CreatesCrmFixtures`; add `travelTo` for reminders/overdue, visibility isolation tests, seeded-matrix drift test, strict_types/final guard, `defaultSort` guard, policy `deleteAny` guard | **Reuse** | Proven against the exact Filament/Livewire versions |
| Static Analysis | PHPStan 2 + Larastan 3.10 level 5, `checkModelProperties: true`, paths incl. tests, no baseline, 25 documented false positives; PHPStan/Pint not in CI | Same config minus the ExampleTest ignore; `phpVersion: 80300`; catalogue created only at the first unavoidable false positive; **runs in CI** | **Reuse + extend** | Same toolchain; keeps both codebases reviewable with one gate |
| Documentation | README + 34 KB CLAUDE.md constitution + DEPLOYMENT.md; K-nn amendments, D/K/P/ISS ids; GoLive checklist; false-positive catalogue; stale/duplicated token docs | README + scaled CLAUDE.md + CONTRIBUTING.md + `docs/{ARCHITECTURE,DATABASE,DECISIONS,PERMISSIONS,MODULES,DEPLOYMENT,GoLive_Checklist,Static_Analysis_Known_False_Positives,DESIGN_TOKENS}.md` + `docs/decisions/ADR-nnn.md` with a template | **Adapt** | Keep traceability without the external-report authority model |
| Search | Filament defaults; `$recordTitleAttribute` only; scope inherited from `getEloquentQuery()` | Searchable attributes, details, actions, scoped query, `canGloballySearch`, key bindings, debounce, negative scope test | **Replace** | Search is a primary CRM surface; Stockflow never invested in it |
| Import/Export | None (queue infrastructure present, `ShouldQueue` used) | Filament `ImportAction`/`ExportAction`/`ExportBulkAction`, importers with upsert on normalised email/phone, scoped exporters, private disk, policies, history resources, `model:prune` | **Replace (new)** | Spec requires it; built-ins need only the queue drain already planned |
| Dashboard/Reports | Statistics pages hosting widgets; statistics services with `selectRaw/groupBy`; hand-rolled report filter partials; `StatsOverview`/`TableWidget` only, no charts | Filament `Dashboard` + `HasFiltersForm`; `StatsOverview`, `ChartWidget`, `TableWidget`; report pages backed by `*Analytics` services; `ExportAction` per report | **Adapt** | Keep service and permission layering; replace bespoke filter UI |
| File Attachments | Plain `FileUpload` on the public disk without authorisation (invoices publicly addressable); a proper service tier exists only for menu images | Polymorphic `attachments` + `AttachmentStorage` (private disk, MIME sniff, UUID, per-entity dir, transactional rollback, observer cleanup) + authorised download route + `preventFilePathTampering` | **Adapt (service tier) / Replace (public uploads)** | CRM documents are confidential |
| Queues/Scheduler | Database queue; per-minute `queue:work --stop-when-empty --max-time=50` gated on non-sync driver; hourly sweep; weekly retention; single cron | Same drain + `tasks:remind`, `tasks:flag-overdue`, `leads:flag-stale`, `activitylog:clean`, `queue:prune-failed`, `model:prune` | **Reuse** (pending Q1) | Identical hosting constraints if the host is the same |
| Deployment | GitHub Actions validate → SSH deploy to Hostinger; fail-closed script with maintenance mode; `app:preflight`; assets built in CI | Same pipeline + Pint/PHPStan steps, PR trigger, `filament:optimize`, stricter preflight; DB engine confirmed first | **Reuse + extend** (pending Q1) | Battle-tested on the same host |
| Git/CI | `master`/`develop`/`develop-<name>`; conventional subjects; undocumented body convention; CI runs phpunit only with `APP_DEBUG=true` | Same branches; written CONTRIBUTING.md; PR template; CI on push + PR with lint/analyse/test; `.gitignore` covers `.claude/worktrees/` | **Reuse + extend** | Keep the habits, remove the ambiguity |

## 2. Pattern classification

### MUST BE REUSED (established standards carried over as-is)

| Pattern | Stockflow location | CRM adaptation |
|---|---|---|
| Resource composition (`Resource` → `Schemas/*Form`, `Schemas/*Infolist`, `Tables/*Table`, `Pages/*`, `RelationManagers/*`), labels via `get*Label()` | `app/Filament/Resources/Suppliers/*`, `Orders/Schemas/OrderInfolist.php` | Every CRM resource |
| `NavigationGroup` enum with closure labels + `NavigationOrderTest` | `app/Enums/NavigationGroup.php`, `tests/Feature/Filament/NavigationOrderTest.php` | Groups Sales/Contacts/Activities/Reports/Settings/System |
| Panel middleware stack and presentation options | `app/Providers/Filament/Concerns/ConfiguresWorkspacePanel.php` | Inlined into the single provider |
| Table conventions (`recordActions`/`toolbarActions`, enum badges, date-range `Filter->schema()`, translated empty states, `authorizeIndividualRecords`) | `app/Filament/Resources/Orders/Tables/OrdersTable.php`, `Users/Tables/UsersTable.php` | Plus `defaultSort` and session persistence on every table |
| View-page workflow header actions + `runWorkflow()` + edit redirect | `app/Filament/Resources/PurchaseOrders/Pages/ViewPurchaseOrder.php`, `EditPurchaseOrder.php` | `RunsWorkflowActions` trait |
| Policy trait with explicit `deleteAny/restoreAny/forceDeleteAny`, `forceDelete=false`; resource `can*` overrides calling `parent::` | `app/Policies/Concerns/ChecksPermissionMatrix.php`, `PurchaseOrderResource.php` | Body reads spatie permission keys |
| Two-layer policy shape (ability + record scope, nullable record, custom verbs, self-delete guard) | `app/Policies/StorePolicy.php`, `InventoryAdjustmentPolicy.php`, `UserPolicy.php` | Verbs `assign`, `convert`, `changeStage`, `merge` |
| Bulk-action authorisation + structural scan test | `tests/Feature/Policies/BulkActionAuthorizationTest.php` | Port + `delete(null)=false ⇒ deleteAny` check |
| Matrix drift + completeness tests | `tests/Feature/Qa/PermissionMatrixDriftTest.php`, `tests/Feature/Access/PermissionMatrixCompletenessTest.php` | Assert seeded spatie tables equal the CRM matrix |
| `FilamentUser` + delegated `canAccessPanel()` + `UserStatus` gating | `app/Models/User.php`, `app/Support/Filament/PanelAccess.php`, `app/Enums/UserStatus.php` | Status active + at least one role |
| Model base conventions (attributes, `casts()`, `@property`, relation generics, `final`, `strict_types`) | `app/Models/PurchaseOrder.php`, `Supplier.php` | `#[Hidden]` everywhere, never `$hidden` |
| Enum conventions (`label()`, `options()`, `TranslatableStatus`, `HasLabel`/`HasColor`) | `app/Enums/OrderStatus.php`, `app/Contracts/TranslatableStatus.php` | Labels only in `enums.php` |
| Sprint-1 migration rules (one table per file, docblock, explicit FK actions, sized strings, reversible `down()`) | `database/migrations/2026_07_28_100004_create_stores_table.php` | Written into `docs/DATABASE.md` + guard test |
| Seeder structure + seeder tests (idempotent `findOrCreate` + `syncPermissions`) | `database/seeders/RolesAndPermissionsSeeder.php`, `tests/Feature/Access/RoleSeedingTest.php` | Default-permission seeder |
| Workflow service with generic `transition()` + append-only log | `app/Services/Orders/OrderWorkflow.php`, `app/Models/OrderStatusLog.php` | `DealStageWorkflow`, `LeadStatusWorkflow` reading allowed transitions from rows |
| Workflow-field guard observer | `app/Observers/PurchaseOrderObserver.php` | Scoped `withoutWorkflowGuard()` helper instead of a static bool |
| Domain exception convention (translated, per domain) | `app/Exceptions/Orders/InvalidOrderTransitionException.php` | `App\Exceptions\{Leads,Deals,Tasks,...}` |
| Filament → service boundary + action-factory classes | `app/Filament/Platform/Support/CompanyLifecycleActions.php` | `LeadActions`, `DealActions`, `TaskActions` |
| Reader/Row read-model DTOs | `app/Services/Catalogue/MenuItemInventorySummaryReader.php` | `TimelineReader`/`TimelineEntry` |
| Statistics service class (user-scoped aggregate SQL, filters never widen scope) | `app/Services/Statistics/OrderAnalytics.php` | `DealAnalytics`, `LeadFunnelMetrics`, … |
| Database-notification envelope for the bell | `app/Notifications/ReplenishmentAlertNotification.php` | Plus `->actions()` and recipient locale |
| Scheduler drain + retention entries | `routes/console.php` | Plus reminder/stale sweeps and `model:prune` |
| Job + sweep-command design (id-only constructor, idempotency window) | `app/Jobs/EvaluateReplenishmentRules.php`, `app/Console/Commands/RunReplenishmentAutomation.php` | `SendTaskReminder`, `tasks:remind` |
| Console command conventions + `app:preflight` | `app/Console/Commands/OnboardCommand.php`, `PreflightCommand.php` | Stricter production checks |
| Invitation flow (nullable password, pending status, signed reset link, activation listener) | `app/Services/Users/UserInvitationService.php`, `app/Listeners/ActivateInvitedUser.php`, `tests/Feature/Access/InvitationPasswordResetTest.php` | Re-authored copy (no company-registration text) |
| Users resource shape | `app/Filament/Resources/Users/*` | Roles select + team + visibility fields |
| Language-switch configuration + user-menu locale actions | `app/Providers/AppServiceProvider.php`, `app/Support/Filament/FilamentLanguageMenuItems.php` | `userPreferredLocale` = user ?? cookie ?? config |
| `lang/` layout, no-hardcoded-strings rule, validation-attribute strategy | `lang/en/users.php`, `lang/ar/validation.php`, `docs/Amendment_K14_Bilingual_Interface.md` | Plus scanner tests |
| Translation guard tests + vendor overrides | `tests/Feature/Filament/TranslationParityTest.php`, `FrameworkTranslationTest.php`, `VendorTranslationFallbackTest.php`, `DefaultLocaleTest.php`, `lang/vendor/filament-tables/ar/table.php` | Extended to JSON files and unused keys |
| Dark-mode two-layer token scheme, RTL rules, AA button fix | `resources/css/filament/admin/theme.css` (core blocks), `docs/Amendment_K23_Dark_Mode.md` | Copied with `--crm-` prefix |
| Vite 8 / Tailwind 4 build + `filament:upgrade` hook | `vite.config.js`, `package.json`, `composer.json` | Without the onboarding entry |
| Audit ledger config, app-owned model, event enum, retention | `config/activitylog.php`, `app/Models/ActivityLog.php`, `app/Enums/ActivityLogEvent.php` | Plus index migration |
| `LogsActivity` whitelist per model | `app/Models/User.php` | On every audited model |
| Service-written business events through a logger class | `app/Services/Integrations/IntegrationActivityLogger.php` | `<Domain>ActivityLogger` |
| Secrets excluded from the ledger + JSON-scan test | `tests/Feature/Audit/AuditLedgerInstrumentationTest.php` | Same |
| Read-only ActivityLog resource + shared table + per-record relation manager | `app/Filament/Resources/ActivityLogs/*` | Own `audit.view` permission |
| Rate limiting + session/auth baseline | `routes/web.php`, `config/session.php`, `phpunit.xml` | `throttle` on public POSTs |
| Test conventions + Livewire idioms + per-role DataProvider HTTP tests | `tests/Feature/Filament/UserResourceTest.php`, `tests/Feature/Access/PermissionMatrixTest.php` | Same |
| Structural source-walking tests | `tests/Feature/Filament/StoreColumnIntegrityTest.php`, `tests/Feature/Seeders/ConfiguredAdminSeederTest.php` | strict_types, `defaultSort`, visibility-trait guards |
| PHPStan config + false-positive policy | `phpstan.neon.dist`, `docs/Static_Analysis_Known_False_Positives.md` | Same |
| Pint default + dotfiles + composer scripts | `composer.json`, `.editorconfig`, `.gitattributes`, `.npmrc`, `.gitignore` | Same |
| README structure, local-env convention (`<app>`/`<app>_testing`), `.env.example` style | `README.md`, `.env.example`, `CLAUDE.md §7b` | `crm`/`crm_testing` |
| Deployment runbook, GoLive checklist, deploy script, workflow, config-guard + wiring tests | `DEPLOYMENT.md`, `docs/GoLive_Checklist.md`, `scripts/deploy-production.sh`, `.github/workflows/deploy.yml`, `tests/Unit/Deployment/DeploymentConfigurationTest.php`, `tests/Feature/System/ProductionWiringTest.php` | Plus lint/analyse steps (pending Q1) |
| Framework auth strings in both locales | `lang/ar/auth.php`, `lang/ar/passwords.php` | Copy |

### SHOULD BE REUSED (useful, needs adaptation)

| Pattern | Stockflow location | CRM adaptation |
|---|---|---|
| Explicit `->columns(1)` on schemas/sections (the rule) | `app/Filament/Resources/Brands/Schemas/BrandForm.php` | Never implicit column counts (RTL defect) |
| Resource query-scoping trait | `app/Filament/Concerns/ScopesQueriesToUserStores.php` | `ScopesQueriesToVisibleRecords` on resources, global search, exporters |
| Scope resolver service shape (read/write asymmetric, fail-closed, Builder-returning) | `app/Services/Access/StoreScopeResolver.php` | `RecordVisibilityResolver` |
| Option-list scoping in forms and relation managers | `app/Filament/Resources/Menus/RelationManagers/MenuStoresRelationManager.php` | Owner/assignee pickers only offer assignable users |
| Child-record policy delegation to the parent policy | `app/Policies/MenuItemPolicy.php` | Activity/Task/Note/Attachment policies delegate to the subject's policy |
| Render hooks + `LoginResponse` rebind + thin provider | `app/Providers/AppServiceProvider.php` | Add `Model::shouldBeStrict`, `Password::defaults`, `URL::forceScheme`; make it `final` |
| Theme structure, ramp generator, contrast documentation | `tools/ramp.mjs`, `config/brand-colors.php`, `docs/DESIGN_TOKENS.md` | CRM palette; automated contrast check |
| Login page extension points | `app/Filament/Auth/Pages/Login.php` | Only if a custom login design is wanted; never copy `authenticate()` |
| Record workspace pages (`#[Url]` tabs, `HasTable` on view pages) | `app/Filament/Resources/Menus/Pages/EditMenu.php`, `MenuItems/Pages/ViewMenuItem.php` | Account/Deal 360 pages |
| Custom page as resource index | `app/Filament/Resources/Products/Pages/ProductTree.php` | `DealBoard` beside `ListDeals` |
| Report date-filter trait | `app/Filament/Pages/Concerns/HasReportDateFilters.php` | Keep parsing; render with Filament `HasFiltersForm` |
| Table-shaped report page | `app/Filament/Pages/Statistics/ProductAvailabilityReport.php` | Join/eager-load instead of per-row queries |
| Module gate trait for pages | `app/Filament/Pages/Concerns/ChecksStatisticsAccess.php` | `ChecksPermission::userCan(Permission)` |
| Custom Blade widget with Livewire action | `app/Filament/Widgets/AiInsightsWidget.php` | Activity feed widget |
| Append-only ledger via observer + RESTRICT parents | `app/Observers/AutomationLogAppendOnlyObserver.php` | `deal_stage_logs`, `lead_status_logs`, `activities`, `activity_log` |
| Enum CHECK helper | `database/migrations/2026_07_28_100010_repair_and_constrain_enum_columns.php` | `App\Support\Database\EnumCheck` |
| Cross-table observers with DI and immutable-attribute guards | `app/Observers/StoreObserver.php`, `ProductUnitsLockObserver.php` | `stage.pipeline_id` immutable |
| Atomic provisioning-style service | `app/Services/Platform/CompanyProvisioningService.php` | `LeadConversionWorkflow` |
| `ActivityLogQuery` + `ActivityLogPresenter` | `app/Services/Audit/*` | Generic diff renderer from model casts |
| ADR/amendment document shape + decision ids | `docs/Amendment_K21_Multi_Panel_Architecture.md`, `CLAUDE.md` | One ADR family with a template |
| CLAUDE.md constitution skeleton | `CLAUDE.md` | Scaled down, no historical appendices |
| Fixture trait, panel bootstrap trait, recording fakes | `tests/Concerns/*`, `tests/Support/FakeAiProvider.php` | `CreatesCrmFixtures` |
| Schema-level tests via `information_schema` | `tests/Feature/Deployment/PlatformAdminRoleCheckMigrationTest.php` | Every CHECK gets a rejection test |
| Locale-aware formatting | `app/Services/Statistics/CatalogueOrderMetrics.php` | `Number::currency(..., locale)` |
| CLI bootstrap commands + `config/admin.php` | `app/Console/Commands/PlatformAdminCommand.php` | `app:onboard` creates the first super admin |
| Service-based file storage tier | `app/Services/Catalogue/MenuItemImageStorage.php` + observer + policy | `AttachmentStorage` on a private disk |
| Encrypted write-only secrets | `app/Models/PosIntegration.php` | Reserved for future integration credentials |
| External provider seam (contract, reason-keyed exception, diagnose command) | `app/Services/Ai/Contracts/AiProvider.php`, `AiDiagnoseCommand.php` | Documented for future mail/messaging providers only |

### CRM-SPECIFIC (designed for the CRM domain)

| Pattern | Why it is new |
|---|---|
| `RecordVisibilityResolver` (own/team/all from permissions + teams) | Replaces store/brand scoping |
| `Permission` enum + `CrmRole` + `RolePermissionMatrix` defaults + runtime-editable roles | Replaces `PermissionMatrix`/`Module`/`AccessLevel` |
| Configurable lead statuses, sources, pipelines, stages, activity types, close reasons as rows with a behavioural `kind` | Spec forbids hard-coding business configuration |
| `LeadStatusWorkflow`, `LeadConversionWorkflow`, `DealStageWorkflow`, `TaskRecurrenceService`, `LeadScoringService` | CRM lifecycle |
| Activities / tasks / notes / attachments / timeline (`TimelineReader`, timeline component) | Core CRM feature absent in Stockflow |
| Global search design, saved views, Filament import/export, charts + filterable dashboard, nine report pages | New surfaces |
| Duplicate detection and merge (normalised email/phone) | CRM data quality |
| Tags (`tags` + `taggables`), teams, notification preferences, settings repository | New infrastructure |
| Custom-field engine (typed values) | Spec item; Stockflow has none |
| Kanban board + calendar pages | New UI |
| Profile page, MFA wiring, `users.locale` | Auth extras |

### SHOULD NOT BE REUSED (Stockflow-specific or complexity)

| Pattern | Location | Reason |
|---|---|---|
| Multi-panel layer (`WorkspacePanel`, `WorkspacePanelRegistry`, `PanelUrl`, launcher, switcher, `AuthenticateWithAdminLogin`, `workspace-panels` config) | `app/Support/Filament/*`, `app/Http/Controllers/WorkspaceLauncherController.php` | Single panel |
| Platform (SaaS) tier and public company registration | `app/Filament/Platform/*`, `app/Services/Platform/*`, `CompanyRegistrationController.php` | Invite-only single organisation |
| All domain services, observers, triggers, enums and lang files for inventory/purchasing/catalogue/orders/replenishment/AI | `app/Services/{Inventory,Purchasing,Catalogue,Orders,Replenishment,Ai,Integrations}/` | Business logic must not be copied |
| Store/brand scope model (`ScopeType`, pivots, `readsAllStores`) | `app/Enums/ScopeType.php` | Replaced by owner/team visibility |
| `users.role` enum column + CHECK + `UserObserver::syncRoles` projection | `app/Observers/UserObserver.php` | spatie is the store |
| `SingleColumnFormSchema` helper | `app/Filament/Support/SingleColumnFormSchema.php` | Dead code; keep the inline rule |
| Native `$table->enum()`, skeleton-then-fill and sprint-bundle migrations, ad hoc index names | later-sprint migrations | Fragile `down()`, unreadable history |
| Process-global `public static bool $allowWorkflowMutation` | `app/Models/PurchaseOrder.php` | Replaced by a scoped helper |
| Eight duplicated `*NumberGenerator` classes | `app/Services/Purchasing/*NumberGenerator.php` | No numbering in v1 |
| Plain `FileUpload` on the public disk without authorisation | `BrandForm.php`, `InventoryAdjustmentForm.php`, `PurchaseInvoiceForm.php` | Confidential documents |
| Page-specific CSS, `[dir='ltr']` exceptions, hex literals in component rules, onboarding tour stack | `theme.css` (outside core blocks), `resources/js/onboarding-tour.js`, `app/Services/Onboarding/` | Stockflow brand/product |
| Adjacency-list plugin + recursive relationships | `app/Models/Company.php`, `ProductTree.php` | No tree UI |
| Installed-but-unused packages (media-library plugin, Horizon, predis, Sanctum) and the `ext-pcntl/ext-posix` platform shim | `composer.json` | Install only what is used |
| Hand-rolled report filter UI | `resources/views/filament/pages/statistics/partials/report-filters.blade.php` | Filament filters instead |
| Audit coupling and constants (`ActivityLogPolicy::module() = Products`, one-case `ActivityLogOutcome`) | `app/Policies/ActivityLogPolicy.php`, `app/Enums/ActivityLogOutcome.php` | Own permission, real enum |
| Hardening absences (no `shouldBeStrict`, `Password::defaults`, `forceScheme`, login listener, security headers) | `bootstrap/app.php`, `AppServiceProvider.php` | Do not inherit the gaps |
| Stock ExampleTests + their PHPStan ignore | `tests/Unit/ExampleTest.php` | Deleted at scaffold |
| Historical CLAUDE.md appendices, four id families, duplicate token docs, redis production block | `CLAUDE.md §8`, `.env.example` | Simpler decision record |
| CI that runs only phpunit with `APP_DEBUG=true` | `.github/workflows/deploy.yml` | Add lint/analyse, drop debug |
| Copied `Login::authenticate()` and shared-login predicate | `app/Filament/Auth/Pages/Login.php` | Single panel needs neither |

## 3. Conflicts between Stockflow patterns and the CRM specification (resolved by priority order)

| Conflict | Resolution |
|---|---|
| Stockflow keeps the permission matrix in code and forbids runtime role editing; the CRM spec lists Roles/Permissions under the admin panel | Spec wins: spatie tables are the store, defaults seeded from code; **the exact option is Q3** because it materially shapes the authorization layer |
| Stockflow is multi-tenant (company scope on every table); the spec does not require tenancy | Spec is silent → **Q2** (one-way door) |
| Stockflow is Arabic-first; the spec requires both languages equally | **Q5** — either is supported by the same plumbing |
| Stockflow hard-codes statuses as enums; the spec says do not hard-code configurable business data | Spec wins: lookups as rows, behaviour via `kind` |
| Stockflow deploys to shared hosting with a scheduler-drained queue; the spec only names Herd locally | **Q1** — the queue/deploy design depends on it |
| Stockflow uploads to the public disk; the spec requires private, authorised files | Spec wins: private disk + download authorisation |
