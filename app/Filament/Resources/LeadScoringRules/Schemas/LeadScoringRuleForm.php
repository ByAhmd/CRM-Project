<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadScoringRules\Schemas;

use App\Enums\LeadScoringRuleKind;
use App\Models\LeadScoringRule;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class LeadScoringRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lead_scoring_rules.sections.rule'))
                    ->description(__('lead_scoring_rules.helpers.rule'))
                    ->schema([
                        Select::make('kind')
                            ->label(__('lead_scoring_rules.fields.kind'))
                            ->options(LeadScoringRuleKind::class)
                            ->required()
                            ->live()
                            ->native(false),

                        Select::make('reference_id')
                            ->label(fn (Get $get): string => self::kind($get) === LeadScoringRuleKind::Status
                                ? __('lead_scoring_rules.fields.status')
                                : __('lead_scoring_rules.fields.source'))
                            ->options(fn (Get $get): array => self::kind($get) === LeadScoringRuleKind::Status
                                ? LeadStatus::query()->orderBy('sort')->get()->mapWithKeys(fn (LeadStatus $status): array => [$status->getKey() => $status->display_name])->all()
                                : LeadSource::query()->orderBy('sort')->get()->mapWithKeys(fn (LeadSource $source): array => [$source->getKey() => $source->display_name])->all())
                            ->required(fn (Get $get): bool => self::kind($get)?->usesReference() ?? false)
                            ->visible(fn (Get $get): bool => self::kind($get)?->usesReference() ?? false)
                            ->dehydrated(fn (Get $get): bool => self::kind($get)?->usesReference() ?? false)
                            ->native(false),

                        Select::make('field')
                            ->label(__('lead_scoring_rules.fields.field'))
                            ->options(fn (): array => self::fieldOptions())
                            ->required(fn (Get $get): bool => self::kind($get)?->usesField() ?? false)
                            ->visible(fn (Get $get): bool => self::kind($get)?->usesField() ?? false)
                            ->dehydrated(fn (Get $get): bool => self::kind($get)?->usesField() ?? false)
                            ->native(false),

                        TextInput::make('within_days')
                            ->label(__('lead_scoring_rules.fields.within_days'))
                            ->helperText(__('lead_scoring_rules.helpers.within_days'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(365)
                            ->required(fn (Get $get): bool => self::kind($get)?->usesDays() ?? false)
                            ->visible(fn (Get $get): bool => self::kind($get)?->usesDays() ?? false)
                            ->dehydrated(fn (Get $get): bool => self::kind($get)?->usesDays() ?? false),

                        TextInput::make('points')
                            ->label(__('lead_scoring_rules.fields.points'))
                            ->helperText(__('lead_scoring_rules.helpers.points'))
                            ->integer()
                            ->minValue(-100)
                            ->maxValue(100)
                            ->required(),

                        Toggle::make('is_active')
                            ->label(__('lead_scoring_rules.fields.is_active'))
                            ->default(true),

                        TextInput::make('sort')
                            ->label(__('lead_scoring_rules.fields.sort'))
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }

    private static function kind(Get $get): ?LeadScoringRuleKind
    {
        $value = $get('kind');

        if ($value instanceof LeadScoringRuleKind) {
            return $value;
        }

        return is_string($value) && $value !== '' ? LeadScoringRuleKind::tryFrom($value) : null;
    }

    /**
     * @return array<string, string>
     */
    private static function fieldOptions(): array
    {
        $options = [];

        foreach (LeadScoringRule::SCORABLE_FIELDS as $field) {
            $options[$field] = (string) __('leads.fields.'.$field);
        }

        return $options;
    }
}
