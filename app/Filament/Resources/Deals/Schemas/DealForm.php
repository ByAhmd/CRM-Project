<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Schemas;

use App\Enums\CustomFieldEntity;
use App\Enums\ForecastCategory;
use App\Enums\StageKind;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\OwnerSelect;
use App\Filament\Support\TagsSelect;
use App\Models\Account;
use App\Models\Deal;
use App\Models\LeadSource;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Product;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * Create / edit deal (decision D-8). The pipeline and the stage are chosen on
 * create only; afterwards the stage changes through the "change stage",
 * "mark as won" and "mark as lost" actions so every move is logged. The
 * amount is entered by hand until the deal has line items, after which it is
 * the sum of the lines (DealAmountCalculator).
 *
 * `$withAccount` is false when the form is embedded in the account page, where
 * the account comes from the owner record.
 */
final class DealForm
{
    public static function configure(Schema $schema, bool $withAccount = true): Schema
    {
        return $schema
            ->components([
                Section::make(__('deals.sections.details'))
                    ->schema([
                        TextInput::make('title')
                            ->label(__('deals.fields.title'))
                            ->required()
                            ->maxLength(150),

                        Grid::make(2)->schema([
                            Select::make('account_id')
                                ->label(__('deals.fields.account'))
                                ->relationship('account', 'name', fn (Builder $query, ?Deal $record): Builder => self::constrainAccounts($query, $record))
                                ->searchable()
                                ->preload()
                                ->nullable()
                                ->native(false)
                                ->live()
                                ->afterStateUpdated(fn (Set $set) => $set('contact_id', null))
                                ->visible($withAccount),

                            Select::make('contact_id')
                                ->label(__('deals.fields.contact'))
                                ->relationship('contact', 'last_name', fn (Builder $query, Get $get, Component $livewire, ?Deal $record): Builder => self::constrainContacts($query, self::accountIdFor($get, $livewire), $record))
                                ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) $record->getAttribute('full_name'))
                                ->searchable(['first_name', 'last_name'])
                                ->preload()
                                ->nullable()
                                ->native(false),
                        ]),

                        Select::make('lead_source_id')
                            ->label(__('deals.fields.source'))
                            ->relationship('source', LeadSource::localisedNameColumn(), fn (Builder $query): Builder => $query->where('is_active', true))
                            ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) $record->getAttribute('display_name'))
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->native(false),

                        ...OwnerSelect::components(Deal::permissionGroup()),
                        TagsSelect::make(),
                    ])
                    ->columns(1),

                Section::make(__('deals.sections.pipeline'))
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('pipeline_id')
                                ->label(__('deals.fields.pipeline'))
                                ->options(fn (): array => Pipeline::query()
                                    ->where('is_active', true)
                                    ->orderBy('sort')
                                    ->orderBy('id')
                                    ->get()
                                    ->mapWithKeys(fn (Pipeline $pipeline): array => [$pipeline->getKey() => $pipeline->display_name])
                                    ->all())
                                ->default(fn (): ?int => self::defaultPipelineId())
                                ->required()
                                ->live()
                                ->native(false)
                                ->afterStateUpdated(fn (Set $set, mixed $state) => $set('stage_id', self::defaultStageId($state)))
                                ->visible(fn (?Deal $record): bool => $record === null),

                            Select::make('stage_id')
                                ->label(__('deals.fields.stage'))
                                ->helperText(__('deals.helpers.initial_stage'))
                                ->options(fn (Get $get): array => self::openStages($get('pipeline_id')))
                                ->default(fn (): ?int => self::defaultStageId(self::defaultPipelineId()))
                                ->required()
                                ->native(false)
                                ->visible(fn (?Deal $record): bool => $record === null),

                            Placeholder::make('pipeline_display')
                                ->label(__('deals.fields.pipeline'))
                                ->content(fn (?Deal $record): string => (string) ($record?->pipeline?->getAttribute('display_name') ?? __('common.placeholders.empty')))
                                ->visible(fn (?Deal $record): bool => $record !== null),

                            Placeholder::make('stage_display')
                                ->label(__('deals.fields.stage'))
                                ->content(fn (?Deal $record): string => (string) ($record?->stage?->getAttribute('display_name') ?? __('common.placeholders.empty')))
                                ->helperText(__('deals.helpers.stage_readonly'))
                                ->visible(fn (?Deal $record): bool => $record !== null),
                        ]),
                    ])
                    ->columns(1),

                Section::make(__('deals.sections.value'))
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('amount')
                                ->label(__('deals.fields.amount'))
                                ->helperText(__('deals.helpers.amount'))
                                ->numeric()
                                ->minValue(0)
                                ->step(0.01)
                                ->default(0)
                                ->required()
                                ->suffix(fn (): string => DealResource::currency())
                                ->disabled(fn (Get $get): bool => self::hasLines($get))
                                ->dehydrated(fn (Get $get): bool => ! self::hasLines($get))
                                ->extraInputAttributes(['dir' => 'ltr']),

                            TextInput::make('probability')
                                ->label(__('deals.fields.probability'))
                                ->helperText(__('deals.helpers.probability'))
                                ->integer()
                                ->minValue(0)
                                ->maxValue(100)
                                ->nullable()
                                ->extraInputAttributes(['dir' => 'ltr']),

                            DatePicker::make('expected_close_date')
                                ->label(__('deals.fields.expected_close_date'))
                                ->native(false)
                                ->nullable(),

                            Select::make('forecast_category')
                                ->label(__('deals.fields.forecast_category'))
                                ->options(ForecastCategory::class)
                                ->default(ForecastCategory::Pipeline->value)
                                ->required()
                                ->native(false),
                        ]),
                    ])
                    ->columns(1),

                Section::make(__('deals.sections.line_items'))
                    ->schema([
                        Repeater::make('products')
                            ->hiddenLabel()
                            ->relationship()
                            // Live so adding or removing a line re-renders the whole form and
                            // the amount field follows the lines in the same submission.
                            ->live()
                            ->schema([
                                Grid::make(2)->schema([
                                    Select::make('product_id')
                                        ->label(__('deals.fields.product'))
                                        ->options(fn (): array => Product::query()
                                            ->where('is_active', true)
                                            ->orderBy(Product::localisedNameColumn())
                                            ->get()
                                            ->mapWithKeys(fn (Product $product): array => [$product->getKey() => $product->display_name])
                                            ->all())
                                        ->required()
                                        ->searchable()
                                        ->native(false)
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                                            $product = filled($state) ? Product::query()->find((int) $state) : null;

                                            if ($product instanceof Product) {
                                                $set('unit_price', $product->unit_price);
                                                $set('description', $product->display_name);
                                            }
                                        }),

                                    TextInput::make('description')
                                        ->label(__('deals.fields.line_description'))
                                        ->maxLength(255),
                                ]),

                                Grid::make(4)->schema([
                                    TextInput::make('quantity')
                                        ->label(__('deals.fields.quantity'))
                                        ->numeric()
                                        ->minValue(0.01)
                                        ->default(1)
                                        ->required()
                                        ->live(onBlur: true)
                                        ->extraInputAttributes(['dir' => 'ltr']),

                                    TextInput::make('unit_price')
                                        ->label(__('deals.fields.unit_price'))
                                        ->numeric()
                                        ->minValue(0)
                                        ->default(0)
                                        ->required()
                                        ->live(onBlur: true)
                                        ->extraInputAttributes(['dir' => 'ltr']),

                                    TextInput::make('discount_percent')
                                        ->label(__('deals.fields.discount_percent'))
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(100)
                                        ->default(0)
                                        ->live(onBlur: true)
                                        ->extraInputAttributes(['dir' => 'ltr']),

                                    Placeholder::make('line_total')
                                        ->label(__('deals.fields.line_total'))
                                        ->content(fn (Get $get): string => self::lineTotal($get('quantity'), $get('unit_price'), $get('discount_percent')))
                                        ->extraAttributes(['dir' => 'ltr']),
                                ]),
                            ])
                            ->orderColumn('sort')
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->defaultItems(0)
                            ->addActionLabel(__('deals.actions.add_line'))
                            ->itemLabel(fn (array $state): ?string => filled($state['description'] ?? null) ? (string) $state['description'] : null),
                    ])
                    ->columns(1)
                    ->collapsible(),

                ...CustomFieldActions::formSection(CustomFieldEntity::Deal),

                Section::make(__('deals.sections.notes'))
                    ->schema([
                        Textarea::make('description')
                            ->label(__('deals.fields.description'))
                            ->rows(4)
                            ->maxLength(5000),
                    ])
                    ->columns(1)
                    ->collapsible(),
            ])
            ->columns(1);
    }

    /**
     * quantity × unit price × (1 − discount %), the same rule DealProductObserver
     * applies on save, shown live while the line is edited.
     */
    public static function lineTotal(mixed $quantity, mixed $unitPrice, mixed $discountPercent): string
    {
        $total = (float) $quantity * (float) $unitPrice * (1 - (float) $discountPercent / 100);

        return number_format(round(max(0, $total), 2), 2, '.', '');
    }

    public static function defaultPipelineId(): ?int
    {
        $id = Pipeline::query()->where('is_default', true)->where('is_active', true)->value('id');

        return $id === null ? null : (int) $id;
    }

    /** The default Open stage of a pipeline, else its first Open stage. */
    public static function defaultStageId(mixed $pipelineId): ?int
    {
        if ($pipelineId === null || $pipelineId === '') {
            return null;
        }

        $id = PipelineStage::query()
            ->where('pipeline_id', (int) $pipelineId)
            ->where('kind', StageKind::Open->value)
            ->orderByDesc('is_default')
            ->orderBy('sort')
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return array<int, string>
     */
    private static function openStages(mixed $pipelineId): array
    {
        if ($pipelineId === null || $pipelineId === '') {
            return [];
        }

        return PipelineStage::query()
            ->where('pipeline_id', (int) $pipelineId)
            ->where('kind', StageKind::Open->value)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (PipelineStage $stage): array => [$stage->getKey() => $stage->display_name])
            ->all();
    }

    /**
     * Whether the form currently holds line items — read from the live
     * repeater state, not the persisted record, so removing the last line
     * re-enables the manual amount before the save (D-8).
     */
    private static function hasLines(Get $get): bool
    {
        $lines = $get('products');

        return is_array($lines) && $lines !== [];
    }

    /**
     * The account the primary contact must belong to: the one chosen in the
     * form, else the owner record when the form is embedded in an account page.
     */
    private static function accountIdFor(Get $get, Component $livewire): ?int
    {
        $accountId = $get('account_id');

        if (filled($accountId)) {
            return (int) $accountId;
        }

        if ($livewire instanceof RelationManager && $livewire->getOwnerRecord() instanceof Account) {
            return (int) $livewire->getOwnerRecord()->getKey();
        }

        return null;
    }

    /**
     * The accounts the actor may see (D-4), plus the deal's current account so
     * an existing choice outside the actor's scope still validates on edit.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function constrainAccounts(Builder $query, ?Deal $record): Builder
    {
        return $query->where(function (Builder $query) use ($record): void {
            $query->whereIn('accounts.id', AccountResource::getEloquentQuery()->select('accounts.id'));

            if ($record?->account_id !== null) {
                $query->orWhere('accounts.id', $record->account_id);
            }
        });
    }

    /**
     * The contacts of the chosen account, else the contacts the actor may see
     * (D-4); the deal's current contact always stays valid.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function constrainContacts(Builder $query, ?int $accountId, ?Deal $record): Builder
    {
        return $query->where(function (Builder $query) use ($accountId, $record): void {
            if ($accountId !== null) {
                $query->where('contacts.account_id', $accountId);
            } else {
                $query->whereIn('contacts.id', ContactResource::getEloquentQuery()->select('contacts.id'));
            }

            if ($record?->contact_id !== null) {
                $query->orWhere('contacts.id', $record->contact_id);
            }
        });
    }
}
