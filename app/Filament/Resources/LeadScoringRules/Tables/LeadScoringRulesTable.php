<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadScoringRules\Tables;

use App\Enums\LeadScoringRuleKind;
use App\Models\LeadScoringRule;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class LeadScoringRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')
                    ->label(__('lead_scoring_rules.fields.kind'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('target')
                    ->label(__('lead_scoring_rules.fields.target'))
                    ->state(fn (LeadScoringRule $record): string => $record->targetLabel()),

                TextColumn::make('points')
                    ->label(__('lead_scoring_rules.fields.points'))
                    ->numeric()
                    ->sortable()
                    ->color(fn (LeadScoringRule $record): string => $record->points < 0 ? 'danger' : 'success'),

                IconColumn::make('is_active')
                    ->label(__('lead_scoring_rules.fields.is_active'))
                    ->boolean(),

                TextColumn::make('sort')
                    ->label(__('lead_scoring_rules.fields.sort'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->filters([
                SelectFilter::make('kind')->label(__('lead_scoring_rules.filters.kind'))->options(LeadScoringRuleKind::class),
                TernaryFilter::make('is_active')->label(__('lead_scoring_rules.filters.is_active')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->emptyStateHeading(__('lead_scoring_rules.empty.heading'))
            ->emptyStateDescription(__('lead_scoring_rules.empty.description'));
    }
}
