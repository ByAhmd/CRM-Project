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
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The signed-in user's own notification preferences (plan section 3.6,
 * decisions D-10, D-19): per event, the in-app bell and mail. Only the events
 * the user can receive are offered — NotificationPreferenceService decides
 * which — and a section left without an offered event disappears. While the
 * installation has no real mailer one notice says so above the sections and
 * the mail toggles are disabled; the choice is still kept. Every save goes
 * through the service, which writes only what changed for offered events and
 * audits it on the user.
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

    /**
     * The events offered to the signed-in user, computed once per request.
     *
     * @var list<NotificationEvent>|null
     */
    private ?array $offered = null;

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

    public function getSubheading(): string
    {
        return __('notifications.helpers.intro');
    }

    /** Every signed-in user manages their own preferences; there is nothing else on the page. */
    public static function canAccess(): bool
    {
        return auth()->user() instanceof User;
    }

    /**
     * Every event per section, in display order. Sections follow the areas of
     * the sidebar the events belong to, then the weekly summary and the
     * system notices.
     *
     * @return array<string, list<NotificationEvent>>
     */
    public static function groups(): array
    {
        return [
            'records' => [NotificationEvent::RecordAssigned],
            'tasks' => [NotificationEvent::TaskReminder, NotificationEvent::TaskOverdue, NotificationEvent::TaskProgress, NotificationEvent::TaskComment, NotificationEvent::TaskCompleted],
            'deals' => [NotificationEvent::DealStageChanged, NotificationEvent::DealClosed],
            'leads' => [NotificationEvent::LeadConverted, NotificationEvent::LeadStale],
            'notes' => [NotificationEvent::NoteMention],
            'summaries' => [NotificationEvent::WeeklySummary],
            'system' => [NotificationEvent::BackupFailed],
        ];
    }

    /**
     * The sections shown to this user: each keeps only its offered events,
     * and a section with none left is dropped.
     *
     * @return array<string, list<NotificationEvent>>
     */
    private function visibleGroups(): array
    {
        $offered = $this->offeredEvents();
        $visible = [];

        foreach (self::groups() as $group => $events) {
            $kept = array_values(array_filter(
                $events,
                static fn (NotificationEvent $event): bool => in_array($event, $offered, true),
            ));

            if ($kept !== []) {
                $visible[$group] = $kept;
            }
        }

        return $visible;
    }

    public function mount(): void
    {
        $offered = array_flip(array_map(
            static fn (NotificationEvent $event): string => $event->value,
            $this->offeredEvents(),
        ));

        $this->form->fill(array_intersect_key(
            app(NotificationPreferenceService::class)->matrixFor($this->user()),
            $offered,
        ));
    }

    public function form(Schema $schema): Schema
    {
        $mailIsConfigured = NotificationChannels::mailIsConfigured();
        $groups = $this->visibleGroups();
        $components = [];

        if ($groups === []) {
            $components[] = EmptyState::make(__('notifications.empty.heading'))
                ->description(__('notifications.empty.description'))
                ->icon(Heroicon::OutlinedBellSlash);
        }

        if ($groups !== [] && ! $mailIsConfigured) {
            $components[] = Callout::make(__('notifications.helpers.mail_not_configured_title'))
                ->description(__('notifications.helpers.mail_not_configured'))
                ->info();
        }

        foreach ($groups as $group => $events) {
            $components[] = $this->section($group, $events, $mailIsConfigured);
        }

        return $schema
            ->components($components)
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
                                ->keyBindings(['mod+s'])
                                ->visible(fn (): bool => $this->offeredEvents() !== []),
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

        // The service drops every event this user is not offered (D-19).
        app(NotificationPreferenceService::class)->update($user, $matrix, $user);

        Notification::make()
            ->title(__('notifications.notifications.saved'))
            ->success()
            ->send();
    }

    /**
     * @param  list<NotificationEvent>  $events
     */
    private function section(string $group, array $events, bool $mailIsConfigured): Component
    {
        return Section::make(__('notifications.sections.'.$group))
            ->description(__('notifications.helpers.sections.'.$group))
            ->icon(self::icon($group))
            ->aside()
            ->schema(array_map(
                static fn (NotificationEvent $event): Fieldset => Fieldset::make($event->getLabel())
                    ->schema([
                        Toggle::make($event->value.'.database')
                            ->label(__('notifications.fields.database'))
                            ->inline(false),

                        Toggle::make($event->value.'.mail')
                            ->label(__('notifications.fields.mail'))
                            ->disabled(! $mailIsConfigured)
                            ->dehydrated()
                            ->inline(false),
                    ])
                    ->columns(2),
                $events,
            ))
            ->columns(1);
    }

    private static function icon(string $group): Heroicon
    {
        return match ($group) {
            'records' => Heroicon::OutlinedUserPlus,
            'tasks' => Heroicon::OutlinedCheckCircle,
            'deals' => Heroicon::OutlinedCurrencyDollar,
            'leads' => Heroicon::OutlinedFunnel,
            'notes' => Heroicon::OutlinedAtSymbol,
            'summaries' => Heroicon::OutlinedChartBar,
            default => Heroicon::OutlinedServerStack,
        };
    }

    /**
     * @return list<NotificationEvent>
     */
    private function offeredEvents(): array
    {
        return $this->offered ??= app(NotificationPreferenceService::class)->offeredEvents($this->user());
    }

    private function user(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }
}
