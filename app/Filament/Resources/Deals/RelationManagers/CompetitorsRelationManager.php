<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\RelationManagers;

use App\Filament\Support\LtrText;
use App\Models\Competitor;
use App\Models\DealCompetitor;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The competitors named on a deal (decision D-8), with who eventually won it
 * and any notes. Every change requires `update` on the deal.
 */
final class CompetitorsRelationManager extends RelationManager
{
    protected static string $relationship = 'competitors';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('deals.sections.competitors');
    }

    public static function getModelLabel(): string
    {
        return __('competitors.navigation.model');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('competitors.fields.name'))
                    ->searchable()
                    ->weight('semibold'),
                LtrText::column(
                    TextColumn::make('website')
                        ->label(__('competitors.fields.website'))
                        ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                        ->placeholder(__('common.placeholders.empty')),
                ),
                IconColumn::make('is_winner')
                    ->label(__('deals.fields.is_winner'))
                    ->state(fn (Competitor $record): bool => (bool) self::pivot($record)?->is_winner)
                    ->boolean(),
                TextColumn::make('notes')
                    ->label(__('deals.fields.competitor_notes'))
                    ->state(fn (Competitor $record): ?string => self::pivot($record)?->getAttribute('notes'))
                    ->limit(60)
                    ->placeholder(__('common.placeholders.empty')),
            ])
            ->defaultSort('competitors.name')
            ->headerActions([
                AttachAction::make()
                    ->label(__('deals.actions.attach_competitor'))
                    ->icon(Heroicon::OutlinedFlag)
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query->where('competitors.is_active', true))
                    ->preloadRecordSelect()
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        ...self::pivotFields(),
                    ])
                    ->authorize(fn (): bool => $this->canUpdateDeal()),
            ])
            // One three-dot menu per row instead of a wall of links; every
            // action keeps its own authorisation, and the menu hides itself
            // when the policy refuses every action in it.
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->schema(self::pivotFields())
                        ->authorize(fn (): bool => $this->canUpdateDeal()),
                    DetachAction::make()
                        ->authorize(fn (): bool => $this->canUpdateDeal()),
                ])
                    ->label(__('app.actions.row_actions'))
                    ->tooltip(__('app.actions.row_actions')),
            ])
            ->emptyStateHeading(__('deals.empty.competitors'));
    }

    /**
     * @return list<Component>
     */
    private static function pivotFields(): array
    {
        return [
            Toggle::make('is_winner')
                ->label(__('deals.fields.is_winner'))
                ->default(false),

            Textarea::make('notes')
                ->label(__('deals.fields.competitor_notes'))
                ->rows(3)
                ->maxLength(2000),
        ];
    }

    private static function pivot(Competitor $record): ?DealCompetitor
    {
        $pivot = $record->getRelationValue('pivot');

        return $pivot instanceof DealCompetitor ? $pivot : null;
    }

    private function canUpdateDeal(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('update', $this->getOwnerRecord());
    }
}
