<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Schemas;

use App\Enums\CustomFieldEntity;
use App\Enums\ForecastCategory;
use App\Enums\StageKind;
use App\Filament\Resources\Accounts\Schemas\AccountForm;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\OwnerSelect;
use App\Filament\Support\TagsSelect;
use App\Models\Account;
use App\Models\Deal;
use App\Models\DealProduct;
use App\Models\LeadSource;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Product;
use Closure;
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
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;
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
    /** The largest value DECIMAL(14,2) holds: deals.amount, deal_products.unit_price and line_total. */
    public const float MAX_AMOUNT = 999999999999.99;

    /** The largest value DECIMAL(12,2) holds: deal_products.quantity. */
    public const float MAX_QUANTITY = 9999999999.99;

    public static function configure(Schema $schema, bool $withAccount = true): Schema
    {
        return $schema
            ->components([
                Section::make(__('deals.sections.details'))
                    ->schema([
                        TextInput::make('title')
                            ->label(__('deals.fields.title'))
                            ->required()
                            ->maxLength(150)
                            ->columnSpanFull(),

                        Select::make('account_id')
                            ->label(__('deals.fields.account'))
                            ->relationship('account', 'name', fn (Builder $query, ?Deal $record): Builder => AccountForm::constrainToPickableAccounts($query, $record?->account_id))
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

                        Select::make('lead_source_id')
                            ->label(__('deals.fields.source'))
                            ->relationship('source', LeadSource::localisedNameColumn(), fn (Builder $query, ?Deal $record): Builder => $query->where(
                                fn (Builder $nested): Builder => $nested->where('is_active', true)
                                    ->when($record?->lead_source_id !== null, fn (Builder $current): Builder => $current->orWhereKey($record?->lead_source_id)),
                            ))
                            ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) $record->getAttribute('display_name'))
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->native(false),

                        ...OwnerSelect::components(Deal::permissionGroup()),
                        TagsSelect::make(),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),

                Section::make(__('deals.sections.pipeline'))
                    ->schema([
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
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),

                Section::make(__('deals.sections.value'))
                    ->schema([
                        TextInput::make('amount')
                            ->label(__('deals.fields.amount'))
                            ->helperText(__('deals.helpers.amount'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(self::MAX_AMOUNT)
                            ->rule('decimal:0,2')
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
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),

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
                                        ->options(fn (?Model $record): array => self::productOptions($record instanceof DealProduct ? $record->product_id : null))
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
                                        ->maxValue(self::MAX_QUANTITY)
                                        ->default(1)
                                        ->required()
                                        ->live(onBlur: true)
                                        ->extraInputAttributes(['dir' => 'ltr']),

                                    TextInput::make('unit_price')
                                        ->label(__('deals.fields.unit_price'))
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(self::MAX_AMOUNT)
                                        ->rule('decimal:0,2')
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
                                        // A displayed value, not an input: the direction lives on
                                        // an inline isolate so the digits keep their order while
                                        // the placeholder keeps the layout's start alignment,
                                        // like every LtrText entry (uniform classic, 2026-09-21;
                                        // the amount is our own formatter's output, escaped all
                                        // the same).
                                        ->content(fn (Get $get): HtmlString => new HtmlString(
                                            '<span class="crm-ltr">'.e(self::lineTotal($get('quantity'), $get('unit_price'), $get('discount_percent'))).'</span>',
                                        )),
                                ]),
                            ])
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                $message = self::linesOverflow($value);

                                if ($message !== null) {
                                    $fail($message);
                                }
                            })
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
     * The active products, plus the line's own product even when it has since
     * been deactivated or soft-deleted, so an unrelated edit of the deal keeps
     * its existing lines valid (design section 6, D-13).
     *
     * @return array<int, string>
     */
    private static function productOptions(?int $currentProductId): array
    {
        return Product::withTrashed()
            ->where(function (Builder $query) use ($currentProductId): void {
                $query->where(fn (Builder $active): Builder => $active->where('is_active', true)->whereNull('deleted_at'));

                if ($currentProductId !== null) {
                    $query->orWhereKey($currentProductId);
                }
            })
            ->orderBy(Product::localisedNameColumn())
            ->get()
            ->mapWithKeys(fn (Product $product): array => [$product->getKey() => $product->display_name])
            ->all();
    }

    /**
     * A translated refusal when a line total, or the sum of the lines that
     * becomes the deal amount, would not fit DECIMAL(14,2) (D-8); null when
     * every figure fits. Each input is bounded on its own field; this catches
     * the products of inputs that fit.
     */
    private static function linesOverflow(mixed $lines): ?string
    {
        if (! is_array($lines)) {
            return null;
        }

        $sum = 0.0;

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $total = (float) self::lineTotal($line['quantity'] ?? 0, $line['unit_price'] ?? 0, $line['discount_percent'] ?? 0);

            if ($total > self::MAX_AMOUNT) {
                return __('deals.validation.line_total_too_large', ['max' => number_format(self::MAX_AMOUNT, 2, '.', ',')]);
            }

            $sum += $total;
        }

        return $sum > self::MAX_AMOUNT
            ? __('deals.validation.lines_total_too_large', ['max' => number_format(self::MAX_AMOUNT, 2, '.', ',')])
            : null;
    }

    /**
     * The contacts the actor may see (D-4), narrowed to the chosen account
     * when there is one; the deal's current contact always stays valid, even
     * when it is outside that scope or soft-deleted.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function constrainContacts(Builder $query, ?int $accountId, ?Deal $record): Builder
    {
        return $query
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where(function (Builder $query) use ($accountId, $record): void {
                $query->where(function (Builder $offered) use ($accountId): void {
                    $offered->whereIn('contacts.id', ContactResource::getEloquentQuery()->select('contacts.id'));

                    if ($accountId !== null) {
                        $offered->where('contacts.account_id', $accountId);
                    }
                });

                if ($record?->contact_id !== null) {
                    $query->orWhere('contacts.id', $record->contact_id);
                }
            });
    }
}
