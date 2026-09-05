<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\ActivateInvitedUser;
use App\Listeners\PersistUserLocale;
use App\Listeners\RecordAuthActivity;
use App\Models\ActivityLog;
use App\Observers\ActivityLogAppendOnlyObserver;
use BezhanSalleh\LanguageSwitch\Events\LocaleChanged;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Table;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->configureModels();
        $this->configureSecurity();
        $this->registerAuditing();
        $this->registerListeners();
        $this->configureLanguageSwitch();
        $this->configureFilamentDefaults();
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

    private function registerAuditing(): void
    {
        ActivityLog::observe(ActivityLogAppendOnlyObserver::class);
        Event::subscribe(RecordAuthActivity::class);
    }

    private function registerListeners(): void
    {
        Event::listen(PasswordReset::class, ActivateInvitedUser::class);
        Event::listen(LocaleChanged::class, PersistUserLocale::class);
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
    }
}
