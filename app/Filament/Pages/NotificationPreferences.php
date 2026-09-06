<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\NavigationGroup;
use App\Enums\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\NotificationPreferenceService;
use App\Support\Notifications\NotificationChannels;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The signed-in user's own notification preferences (plan section 3.6,
 * decision D-10): per event, the in-app bell and mail. Mail toggles are
 * disabled while the installation has no real mailer; the choice is still
 * kept. Every save goes through NotificationPreferenceService, which writes
 * only what changed and audits it on the user.
 *
 * @property-read Schema $form
 */
final class NotificationPreferences extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?int $navigationSort = 90;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::System;
    }

    public static function getNavigationLabel(): string
    {
        return __('notifications.navigation.label');
    }

    public function getTitle(): string
    {
        return __('notifications.title');
    }

    /** Every signed-in user manages their own preferences; there is nothing else on the page. */
    public static function canAccess(): bool
    {
        return auth()->user() instanceof User;
    }

    /**
     * The events per section, in display order. Sections are the five areas
     * of the sidebar the events belong to.
     *
     * @return array<string, list<NotificationEvent>>
     */
    public static function groups(): array
    {
        return [
            'records' => [NotificationEvent::RecordAssigned],
            'tasks' => [NotificationEvent::TaskReminder, NotificationEvent::TaskOverdue],
            'deals' => [NotificationEvent::DealStageChanged, NotificationEvent::DealClosed],
            'leads' => [NotificationEvent::LeadConverted, NotificationEvent::LeadStale],
            'notes' => [NotificationEvent::NoteMention],
        ];
    }

    public function mount(): void
    {
        $this->form->fill(app(NotificationPreferenceService::class)->matrixFor($this->user()));
    }

    public function form(Schema $schema): Schema
    {
        $mailIsConfigured = NotificationChannels::mailIsConfigured();
        $sections = [];

        foreach (self::groups() as $group => $events) {
            $sections[] = Section::make(__('notifications.sections.'.$group))
                ->schema(array_map(
                    static fn (NotificationEvent $event): Fieldset => Fieldset::make($event->getLabel())
                        ->schema([
                            Toggle::make($event->value.'.database')
                                ->label(__('notifications.fields.database'))
                                ->helperText(__('notifications.helpers.database'))
                                ->inline(false),

                            Toggle::make($event->value.'.mail')
                                ->label(__('notifications.fields.mail'))
                                ->helperText($mailIsConfigured ? null : __('notifications.helpers.mail_not_configured'))
                                ->disabled(! $mailIsConfigured)
                                ->dehydrated()
                                ->inline(false),
                        ])
                        ->columns(2),
                    $events,
                ))
                ->columns(1);
        }

        return $schema
            ->components($sections)
            ->columns(1)
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
                                ->label(__('notifications.actions.save'))
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ])->key('form-actions'),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);

        $user = $this->user();
        $matrix = [];

        foreach ($this->form->getState() as $event => $channels) {
            if (! is_array($channels)) {
                continue;
            }

            $matrix[(string) $event] = [
                'database' => (bool) ($channels['database'] ?? false),
                'mail' => (bool) ($channels['mail'] ?? false),
            ];
        }

        app(NotificationPreferenceService::class)->update($user, $matrix, $user);

        Notification::make()
            ->title(__('notifications.notifications.saved'))
            ->success()
            ->send();
    }

    private function user(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }
}
