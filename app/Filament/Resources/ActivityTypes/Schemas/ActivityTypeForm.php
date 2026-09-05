<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityTypes\Schemas;

use App\Enums\ActivityKind;
use App\Enums\BadgeColor;
use App\Models\ActivityType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;

final class ActivityTypeForm
{
    /**
     * The outlined Heroicons an administrator may give an activity type. Every
     * ActivityKind icon is in the list so a seeded system row always resolves.
     */
    public const array ICONS = [
        Heroicon::OutlinedPhone,
        Heroicon::OutlinedCalendarDays,
        Heroicon::OutlinedEnvelope,
        Heroicon::OutlinedDocumentText,
        Heroicon::OutlinedCheckCircle,
        Heroicon::OutlinedCog6Tooth,
        Heroicon::OutlinedClipboardDocumentList,
        Heroicon::OutlinedChatBubbleLeftRight,
        Heroicon::OutlinedVideoCamera,
        Heroicon::OutlinedUserGroup,
        Heroicon::OutlinedBuildingOffice,
        Heroicon::OutlinedDevicePhoneMobile,
        Heroicon::OutlinedPaperAirplane,
        Heroicon::OutlinedBell,
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('activity_types.sections.details'))
                    ->schema([
                        TextInput::make('name_ar')
                            ->label(__('activity_types.fields.name_ar'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?ActivityType $record): object => Rule::unique('activity_types', 'name_ar')
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('activity_types.validation.name_unique')]),

                        TextInput::make('name_en')
                            ->label(__('activity_types.fields.name_en'))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?ActivityType $record): object => Rule::unique('activity_types', 'name_en')
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('activity_types.validation.name_unique')]),

                        Select::make('kind')
                            ->label(__('activity_types.fields.kind'))
                            ->helperText(fn (?ActivityType $record): string => ($record !== null && $record->is_system)
                                ? __('activity_types.helpers.kind_locked')
                                : __('activity_types.helpers.kind'))
                            ->options(ActivityKind::class)
                            ->required()
                            ->native(false)
                            ->disabled(fn (?ActivityType $record): bool => $record !== null && $record->is_system)
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state, mixed $old): void {
                                $kind = self::kindFrom($state);

                                if ($kind === null) {
                                    return;
                                }

                                // The kind only supplies a default: an icon the administrator chose
                                // deliberately (anything but the previous kind's icon) is kept.
                                $icon = $get('icon');

                                if (blank($icon) || $icon === self::kindFrom($old)?->getIcon()->name) {
                                    $set('icon', $kind->getIcon()->name);
                                }
                            }),

                        Toggle::make('is_active')
                            ->label(__('activity_types.fields.is_active'))
                            ->helperText(__('activity_types.helpers.is_active'))
                            ->default(true),

                        TextInput::make('sort')
                            ->label(__('activity_types.fields.sort'))
                            ->helperText(__('activity_types.helpers.sort'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(65535)
                            ->default(0)
                            ->required(),
                    ])
                    ->columns(1),

                Section::make(__('activity_types.sections.appearance'))
                    ->schema([
                        Select::make('icon')
                            ->label(__('activity_types.fields.icon'))
                            ->helperText(__('activity_types.helpers.icon'))
                            ->placeholder(__('activity_types.placeholders.no_icon'))
                            ->options(self::iconOptions())
                            ->nullable()
                            ->native(false),

                        Select::make('color')
                            ->label(__('activity_types.fields.color'))
                            ->helperText(__('activity_types.helpers.color'))
                            ->options(BadgeColor::class)
                            ->default(BadgeColor::Gray->value)
                            ->required()
                            ->native(false),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }

    /**
     * Heroicon case name => translated label, in ICONS order.
     *
     * @return array<string, string>
     */
    public static function iconOptions(): array
    {
        $options = [];

        foreach (self::ICONS as $icon) {
            $options[$icon->name] = __('activity_types.options.icons.'.$icon->name);
        }

        return $options;
    }

    private static function kindFrom(mixed $state): ?ActivityKind
    {
        return match (true) {
            $state instanceof ActivityKind => $state,
            is_string($state) => ActivityKind::tryFrom($state),
            default => null,
        };
    }
}
