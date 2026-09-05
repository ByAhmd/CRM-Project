<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Enums\NavigationGroup;
use App\Enums\Permission;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Organisation-wide settings (decisions D-2, D-8): name, currency, timezone,
 * week start. Every save is audited with before/after values.
 *
 * @property-read Schema $form
 */
final class GeneralSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 90;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Settings;
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.general.navigation');
    }

    public function getTitle(): string
    {
        return __('settings.general.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can(Permission::SettingsManage->value);
    }

    public function mount(): void
    {
        $settings = app(SettingsRepository::class);

        $this->form->fill([
            'organisation_name' => $settings->organisationName(),
            'currency' => $settings->currency(),
            'timezone' => $settings->timezone(),
            'week_starts_on' => $settings->weekStartsOn(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('settings.general.sections.organisation'))
                    ->schema([
                        TextInput::make('organisation_name')
                            ->label(__('settings.general.fields.organisation_name'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(150),

                        Select::make('currency')
                            ->label(__('settings.general.fields.currency'))
                            ->helperText(__('settings.general.helpers.currency'))
                            ->options(fn (): array => self::currencyOptions())
                            ->required()
                            ->native(false),

                        Select::make('timezone')
                            ->label(__('settings.general.fields.timezone'))
                            ->helperText(__('settings.general.helpers.timezone'))
                            ->options(fn (): array => array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                            ->searchable()
                            ->required()
                            ->native(false),

                        Select::make('week_starts_on')
                            ->label(__('settings.general.fields.week_starts_on'))
                            ->options(fn (): array => self::dayOptions())
                            ->required()
                            ->native(false),
                    ])
                    ->columns(1),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label(__('settings.actions.save'))
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ])->key('form-actions'),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);

        $data = $this->form->getState();
        $actor = auth()->user();

        app(SettingsRepository::class)->update([
            SettingsRepository::ORGANISATION_NAME => (string) $data['organisation_name'],
            SettingsRepository::CURRENCY => (string) $data['currency'],
            SettingsRepository::TIMEZONE => (string) $data['timezone'],
            SettingsRepository::WEEK_STARTS_ON => (int) $data['week_starts_on'],
        ], $actor instanceof User ? $actor : null);

        Notification::make()
            ->title(__('settings.notifications.saved'))
            ->success()
            ->send();
    }

    /**
     * @return array<string, string>
     */
    private static function currencyOptions(): array
    {
        /** @var array<string, string> $currencies */
        $currencies = __('settings.currencies');

        return $currencies;
    }

    /**
     * @return array<int, string>
     */
    private static function dayOptions(): array
    {
        /** @var array<int, string> $days */
        $days = __('settings.days');

        return $days;
    }
}
