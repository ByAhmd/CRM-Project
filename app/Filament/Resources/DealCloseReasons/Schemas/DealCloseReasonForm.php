<?php

declare(strict_types=1);

namespace App\Filament\Resources\DealCloseReasons\Schemas;

use App\Enums\CloseReasonKind;
use App\Models\DealCloseReason;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

final class DealCloseReasonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('close_reasons.sections.details'))
                    ->schema([
                        Select::make('kind')
                            ->label(__('close_reasons.fields.kind'))
                            ->helperText(__('close_reasons.helpers.kind'))
                            ->options(CloseReasonKind::class)
                            ->required()
                            ->native(false)
                            ->columnSpanFull(),

                        TextInput::make('name_ar')
                            ->label(__('close_reasons.fields.name_ar'))
                            ->placeholder(__('close_reasons.placeholders.name_ar'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?DealCloseReason $record, Get $get): object => Rule::unique('deal_close_reasons', 'name_ar')
                                    ->where('kind', self::kindValue($get('kind')))
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('close_reasons.validation.name_unique')]),

                        TextInput::make('name_en')
                            ->label(__('close_reasons.fields.name_en'))
                            ->placeholder(__('close_reasons.placeholders.name_en'))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->required()
                            ->minLength(2)
                            ->maxLength(100)
                            ->rules([
                                fn (?DealCloseReason $record, Get $get): object => Rule::unique('deal_close_reasons', 'name_en')
                                    ->where('kind', self::kindValue($get('kind')))
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => __('close_reasons.validation.name_unique')]),

                        Toggle::make('is_active')
                            ->label(__('close_reasons.fields.is_active'))
                            ->helperText(__('close_reasons.helpers.is_active'))
                            ->default(true),

                        TextInput::make('sort')
                            ->label(__('close_reasons.fields.sort'))
                            ->helperText(__('close_reasons.helpers.sort'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(65535)
                            ->default(0)
                            ->required(),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),
            ])
            ->columns(1);
    }

    /**
     * The kind as stored: the form state may hold the enum case (edit) or its
     * backing value (create), and uniqueness is scoped per kind.
     */
    private static function kindValue(mixed $state): string
    {
        if ($state instanceof CloseReasonKind) {
            return $state->value;
        }

        return is_scalar($state) ? (string) $state : '';
    }
}
