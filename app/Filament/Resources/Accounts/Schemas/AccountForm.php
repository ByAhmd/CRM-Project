<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Schemas;

use App\Enums\AccountType;
use App\Enums\CompanySize;
use App\Enums\CustomFieldEntity;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Support\AddressSchema;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\DuplicateWarning;
use App\Filament\Support\OwnerSelect;
use App\Filament\Support\TagsSelect;
use App\Models\Account;
use App\Models\Industry;
use App\Models\User;
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
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Create / edit account (decisions D-4, D-6).
 *
 * The lifecycle type follows the deals: a prospect becomes a customer on its
 * first won deal. Only holders of `account.set_type` choose the type (and
 * customer_since) by hand; for everyone else both fields are read-only and
 * never saved, so a new account keeps the default prospect.
 *
 * Pickers offer only what the actor may read (D-4) and keep the record's
 * current value valid even when it has since been deactivated or deleted, so
 * an unrelated edit is never blocked by a retired reference.
 */
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
                                ->live()
                                ->disabled(fn (?Account $record): bool => ! self::maySetType($record)),

                            DatePicker::make('customer_since')
                                ->label(__('accounts.fields.customer_since'))
                                ->native(false)
                                ->disabled(fn (?Account $record): bool => ! self::maySetType($record))
                                ->visible(fn (Get $get): bool => $get('type') === AccountType::Customer->value
                                    || $get('type') === AccountType::Customer),
                        ]),

                        Grid::make(2)->schema([
                            Select::make('industry_id')
                                ->label(__('accounts.fields.industry'))
                                ->relationship('industry', Industry::localisedNameColumn(), fn (Builder $query, ?Account $record): Builder => $query->where(
                                    fn (Builder $nested): Builder => $nested->where('is_active', true)
                                        ->when($record?->industry_id !== null, fn (Builder $current): Builder => $current->orWhereKey($record?->industry_id)),
                                ))
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
                            ->relationship('parent', 'name', fn (Builder $query, ?Account $record): Builder => self::constrainToPickableAccounts($query, $record?->parent_account_id)
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

    /**
     * The accounts a picker may offer: those the actor may read (D-4), plus
     * the record's current account even when it is outside that scope or
     * soft-deleted, so an existing link keeps validating on edit (D-13).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function constrainToPickableAccounts(Builder $query, ?int $currentAccountId): Builder
    {
        return $query
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where(function (Builder $nested) use ($currentAccountId): void {
                $nested->whereIn('accounts.id', AccountResource::getEloquentQuery()->select('accounts.id'));

                if ($currentAccountId !== null) {
                    $nested->orWhere('accounts.id', $currentAccountId);
                }
            });
    }

    /** Whether the actor may set the lifecycle type by hand (D-6). */
    private static function maySetType(?Account $record): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can('setType', $record ?? Account::class);
    }
}
