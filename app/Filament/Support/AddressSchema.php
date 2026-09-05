<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

/**
 * The address block shared by accounts, contacts and leads.
 */
final class AddressSchema
{
    public static function section(): Section
    {
        return Section::make(__('address.section'))
            ->schema([
                TextInput::make('address_line')
                    ->label(__('address.fields.address_line'))
                    ->maxLength(255),

                Grid::make(2)->schema([
                    TextInput::make('city')
                        ->label(__('address.fields.city'))
                        ->maxLength(100),

                    TextInput::make('region')
                        ->label(__('address.fields.region'))
                        ->maxLength(100),
                ]),

                Grid::make(2)->schema([
                    Select::make('country')
                        ->label(__('address.fields.country'))
                        ->options(fn (): array => self::countryOptions())
                        ->default('SA')
                        ->searchable()
                        ->native(false),

                    TextInput::make('postal_code')
                        ->label(__('address.fields.postal_code'))
                        ->maxLength(20)
                        ->extraInputAttributes(['dir' => 'ltr']),
                ]),
            ])
            ->columns(1)
            ->collapsible()
            ->collapsed();
    }

    /**
     * @return list<TextEntry>
     */
    public static function entries(): array
    {
        return [
            TextEntry::make('address_line')->label(__('address.fields.address_line'))->placeholder(__('common.placeholders.empty')),
            TextEntry::make('city')->label(__('address.fields.city'))->placeholder(__('common.placeholders.empty')),
            TextEntry::make('region')->label(__('address.fields.region'))->placeholder(__('common.placeholders.empty')),
            TextEntry::make('country')
                ->label(__('address.fields.country'))
                ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : (self::countryOptions()[$state] ?? $state))
                ->placeholder(__('common.placeholders.empty')),
            TextEntry::make('postal_code')->label(__('address.fields.postal_code'))->placeholder(__('common.placeholders.empty')),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function countryOptions(): array
    {
        /** @var array<string, string> $countries */
        $countries = __('address.countries');

        return $countries;
    }
}
