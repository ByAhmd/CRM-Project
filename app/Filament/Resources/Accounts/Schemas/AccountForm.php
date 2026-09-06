<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Schemas;

use App\Enums\AccountType;
use App\Enums\CompanySize;
use App\Enums\CustomFieldEntity;
use App\Filament\Support\AddressSchema;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\DuplicateWarning;
use App\Filament\Support\OwnerSelect;
use App\Filament\Support\TagsSelect;
use App\Models\Account;
use App\Models\Industry;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('accounts.sections.details'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('accounts.fields.name'))
                            ->placeholder(__('accounts.placeholders.name'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(150)
                            ->live(onBlur: true),

                        DuplicateWarning::forAccount(),

                        Grid::make(2)->schema([
                            Select::make('type')
                                ->label(__('accounts.fields.type'))
                                ->helperText(__('accounts.helpers.type'))
                                ->options(AccountType::class)
                                ->default(AccountType::Prospect->value)
                                ->required()
                                ->native(false)
                                ->live(),

                            DatePicker::make('customer_since')
                                ->label(__('accounts.fields.customer_since'))
                                ->native(false)
                                ->visible(fn (Get $get): bool => $get('type') === AccountType::Customer->value
                                    || $get('type') === AccountType::Customer),
                        ]),

                        Grid::make(2)->schema([
                            Select::make('industry_id')
                                ->label(__('accounts.fields.industry'))
                                ->relationship('industry', Industry::localisedNameColumn(), fn (Builder $query): Builder => $query->where('is_active', true))
                                ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) $record->getAttribute('display_name'))
                                ->searchable()
                                ->preload()
                                ->nullable()
                                ->native(false),

                            Select::make('size')
                                ->label(__('accounts.fields.size'))
                                ->options(CompanySize::class)
                                ->nullable()
                                ->native(false),
                        ]),

                        Select::make('parent_account_id')
                            ->label(__('accounts.fields.parent'))
                            ->helperText(__('accounts.helpers.parent'))
                            ->relationship('parent', 'name', fn (Builder $query, ?Account $record): Builder => $query
                                ->when($record !== null, fn (Builder $nested): Builder => $nested->whereKeyNot($record?->getKey())))
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->native(false),
                    ])
                    ->columns(1),

                Section::make(__('accounts.sections.contact'))
                    ->schema([
                        TextInput::make('website')
                            ->label(__('accounts.fields.website'))
                            ->url()
                            ->maxLength(255)
                            ->extraInputAttributes(['dir' => 'ltr']),

                        Grid::make(2)->schema([
                            TextInput::make('email')
                                ->label(__('accounts.fields.email'))
                                ->email()
                                ->maxLength(190)
                                ->live(onBlur: true)
                                ->extraInputAttributes(['dir' => 'ltr']),

                            TextInput::make('phone')
                                ->label(__('accounts.fields.phone'))
                                ->tel()
                                ->maxLength(30)
                                ->live(onBlur: true)
                                ->extraInputAttributes(['dir' => 'ltr']),
                        ]),
                    ])
                    ->columns(1),

                AddressSchema::section(),

                Section::make(__('accounts.sections.ownership'))
                    ->schema([
                        ...OwnerSelect::components(Account::permissionGroup()),
                        TagsSelect::make(),
                    ])
                    ->columns(1),

                ...CustomFieldActions::formSection(CustomFieldEntity::Account),

                Section::make(__('accounts.sections.notes'))
                    ->schema([
                        Textarea::make('description')
                            ->label(__('accounts.fields.description'))
                            ->rows(4)
                            ->maxLength(5000),
                    ])
                    ->columns(1)
                    ->collapsible(),
            ])
            ->columns(1);
    }
}
