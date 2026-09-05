<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\Schemas;

use App\Filament\Support\AddressSchema;
use App\Filament\Support\DuplicateWarning;
use App\Filament\Support\OwnerSelect;
use App\Filament\Support\TagsSelect;
use App\Models\Contact;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ContactForm
{
    public static function configure(Schema $schema, bool $withAccount = true): Schema
    {
        return $schema
            ->components([
                Section::make(__('contacts.sections.details'))
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('first_name')
                                ->label(__('contacts.fields.first_name'))
                                ->required()
                                ->minLength(2)
                                ->maxLength(80),

                            TextInput::make('last_name')
                                ->label(__('contacts.fields.last_name'))
                                ->required()
                                ->minLength(2)
                                ->maxLength(80),
                        ]),

                        Select::make('account_id')
                            ->label(__('contacts.fields.account'))
                            ->helperText(__('contacts.helpers.account'))
                            ->relationship('account', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->native(false)
                            ->visible($withAccount),

                        Grid::make(2)->schema([
                            TextInput::make('job_title')
                                ->label(__('contacts.fields.job_title'))
                                ->maxLength(100),

                            TextInput::make('department')
                                ->label(__('contacts.fields.department'))
                                ->maxLength(100),
                        ]),

                        Toggle::make('is_primary')
                            ->label(__('contacts.fields.is_primary'))
                            ->helperText(__('contacts.helpers.is_primary'))
                            ->default(false),
                    ])
                    ->columns(1),

                Section::make(__('contacts.sections.contact'))
                    ->schema([
                        TextInput::make('email')
                            ->label(__('contacts.fields.email'))
                            ->email()
                            ->maxLength(190)
                            ->live(onBlur: true)
                            ->extraInputAttributes(['dir' => 'ltr']),

                        Grid::make(2)->schema([
                            TextInput::make('mobile')
                                ->label(__('contacts.fields.mobile'))
                                ->tel()
                                ->maxLength(30)
                                ->live(onBlur: true)
                                ->extraInputAttributes(['dir' => 'ltr']),

                            TextInput::make('phone')
                                ->label(__('contacts.fields.phone'))
                                ->tel()
                                ->maxLength(30)
                                ->live(onBlur: true)
                                ->extraInputAttributes(['dir' => 'ltr']),
                        ]),

                        DuplicateWarning::forContact(),

                        Grid::make(2)->schema([
                            Select::make('preferred_locale')
                                ->label(__('contacts.fields.preferred_locale'))
                                ->helperText(__('contacts.helpers.preferred_locale'))
                                ->options(fn (): array => self::localeOptions())
                                ->default(fn (): string => (string) config('app.locale'))
                                ->native(false),

                            TextInput::make('linkedin_url')
                                ->label(__('contacts.fields.linkedin_url'))
                                ->url()
                                ->maxLength(255)
                                ->extraInputAttributes(['dir' => 'ltr']),
                        ]),
                    ])
                    ->columns(1),

                AddressSchema::section(),

                Section::make(__('contacts.sections.ownership'))
                    ->schema([
                        ...OwnerSelect::components(Contact::permissionGroup()),
                        TagsSelect::make(),
                    ])
                    ->columns(1),

                Section::make(__('contacts.sections.notes'))
                    ->schema([
                        Textarea::make('description')
                            ->label(__('contacts.fields.description'))
                            ->rows(4)
                            ->maxLength(5000),
                    ])
                    ->columns(1)
                    ->collapsible(),
            ])
            ->columns(1);
    }

    /**
     * @return array<string, string>
     */
    private static function localeOptions(): array
    {
        $options = [];

        foreach ((array) config('app.locales') as $locale) {
            $options[$locale] = LanguageSwitch::make()->getLabel($locale);
        }

        return $options;
    }
}
