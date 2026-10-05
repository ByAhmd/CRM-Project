<?php

declare(strict_types=1);

namespace App\Providers;

use App\Filament\Auth\Http\Responses\LoginResponse;
use App\Filament\Auth\Http\Responses\LogoutResponse;
use App\Listeners\RecordAuthActivity;
use App\Models\ActivityLog;
use App\Models\CustomField;
use App\Observers\ActivityLogAppendOnlyObserver;
use App\Policies\ExportPolicy;
use App\Policies\ImportPolicy;
use App\Services\CustomFields\CustomFieldRegistry;
use App\Services\Leads\LeadScoringService;
use App\Services\Settings\SettingsRepository;
use App\Support\Landing\LandingPageChrome;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\Actions\ActionGroup;
use Filament\Actions\Exports\Models\Export as FilamentExport;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import as FilamentImport;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Filament\Forms\Components\FileUpload;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One rule cache per process: the lead observer, the status workflow and
        // the rule observer all see the same instance, so a rule change refreshes
        // the cache every path reads from.
        $this->app->singleton(LeadScoringService::class);

        // One custom-field definition memo per request or queued job: every
        // form, table, filter, action, importer row and exporter of the same
        // request reads the definitions once. Scoped instances are dropped
        // between requests and jobs, and registerCustomFieldRegistryFlush()
        // drops it the moment a definition changes, so nothing reads stale.
        $this->app->scoped(CustomFieldRegistry::class);
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureSecurity();
        $this->configureImportExportAuthorisation();
        $this->registerCustomFieldRegistryFlush();
        // Listeners in app/Listeners are auto-discovered; registering them here
        // as well ran every handler twice per event.
        $this->registerAuditing();
        $this->configureLanguageSwitch();
        $this->configureFilamentDefaults();
        $this->configurePanels();
        $this->configureLandingPage();
    }

    /**
     * Fail loudly outside production when a form writes an attribute the model
     * does not declare or reads one it never loaded. Lazy-loading prevention is
     * deliberately NOT enabled: spatie/laravel-permission and Filament resolve
     * relations lazily by design.
     */
    private function configureModels(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());
    }

    /** D-11: password policy; https is forced in production. */
    private function configureSecurity(): void
    {
        Password::defaults(fn (): Password => Password::min(12)
            ->mixedCase()
            ->numbers()
            ->uncompromised());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }

    /**
     * Filament's export-download and failed-rows-download routes bind
     * Filament's own Export / Import models and run under the plain `web`
     * group; they authorise through Gate::getPolicyFor() of the bound class.
     * Registering the application policies for the base classes makes those
     * routes enforce the same owner / reviewer scope and account status as the
     * history screens (D-4, D-11, D-13).
     *
     * The failed-rows CSV reproduces every uploaded cell, so it is written with
     * formula cells neutralised (CWE-1236) for every importer.
     */
    private function configureImportExportAuthorisation(): void
    {
        Gate::policy(FilamentExport::class, ExportPolicy::class);
        Gate::policy(FilamentImport::class, ImportPolicy::class);

        Importer::preventFormulaInjection();
    }

    /** A saved, deleted or restored definition is read afresh by the next consumer (D-9). */
    private function registerCustomFieldRegistryFlush(): void
    {
        $flush = function (): void {
            $this->app->forgetInstance(CustomFieldRegistry::class);
        };

        CustomField::saved($flush);
        CustomField::deleted($flush);
    }

    private function registerAuditing(): void
    {
        ActivityLog::observe(ActivityLogAppendOnlyObserver::class);
        Event::subscribe(RecordAuthActivity::class);
    }

    /**
     * D-5: Arabic default. The plugin would otherwise honour Accept-Language
     * before app.locale and open English browsers in English. Resolution order:
     * the signed-in user's stored choice, then the switch cookie, then the
     * configured default — never the browser.
     */
    private function configureLanguageSwitch(): void
    {
        LanguageSwitch::configureUsing(function (LanguageSwitch $switch): void {
            $switch
                ->locales((array) config('app.locales'))
                ->nativeLabel()
                ->visible(insidePanels: false)
                ->userPreferredLocale(function (): string {
                    $user = auth()->user();
                    $stored = $user?->getAttribute('locale');

                    if (is_string($stored) && in_array($stored, (array) config('app.locales'), true)) {
                        return $stored;
                    }

                    return (string) (request()->cookie('filament_language_switch_locale') ?? config('app.locale'));
                });
        });
    }

    /**
     * What every panel shares beyond its own provider (D-23).
     *
     * - Sign-in lands on the workspace screen, or on the page the user was
     *   following when it lies in a panel they may enter; sign-out leads to
     *   the one sign-in page. Bound here, in boot(), because Filament binds
     *   its own responses while its package registers, after register().
     * - Every Filament date-time column, entry and picker renders (and a
     *   picker reads its input) in the organisation timezone from General
     *   Settings, the same zone the calendar and the reports use; values stay
     *   stored in app.timezone. The closure is resolved on use, so a changed
     *   setting takes effect on the next render and nothing touches the
     *   database at boot. Set once here for all five panels.
     */
    private function configurePanels(): void
    {
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
        $this->app->bind(LogoutResponseContract::class, LogoutResponse::class);

        FilamentTimezone::set(static fn (): string => app(SettingsRepository::class)->timezone());
    }

    /**
     * The landing page's own head tags (D-24): the meta description, Open
     * Graph, the canonical address. Scoped to the landing's chrome, so they
     * render on the landing page only and never inside a panel.
     */
    private function configureLandingPage(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            static fn (): View => view('landing.partials.meta'),
            scopes: LandingPageChrome::class,
        );
    }

    /** Conventions every table and upload inherits without repeating them per resource. */
    private function configureFilamentDefaults(): void
    {
        Table::configureUsing(function (Table $table): void {
            $table
                ->persistFiltersInSession()
                ->persistSortInSession()
                ->persistSearchInSession()
                ->paginated([10, 25, 50, 100])
                ->defaultPaginationPageOption(25);
        });

        FileUpload::configureUsing(function (FileUpload $upload): void {
            $upload->preventFilePathTampering();
        });

        // A three-dot menu is positioned against the viewport (Filament's
        // "teleport" is the fixed positioning strategy — the panel stays in its
        // row), so the bounded table container (theme.css, sticky header) can
        // no longer clip it. Its stacking over the sticky header is theme.css's
        // job: a row with an open menu rises above the header.
        ActionGroup::configureUsing(function (ActionGroup $group): void {
            $group->dropdownTeleport();
        });
    }
}
