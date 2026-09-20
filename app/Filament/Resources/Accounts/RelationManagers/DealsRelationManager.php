<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Schemas\DealForm;
use App\Filament\Resources\Deals\Schemas\DealInfolist;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\LtrText;
use App\Models\Deal;
use App\Models\User;
use App\Services\Deals\DealAmountCalculator;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The deals of an account (decision D-6): a read-only listing within the
 * actor's visibility scope, plus creating a deal that inherits the account.
 * Opening a deal leads to its own page, where the workflow actions live.
 */
final class DealsRelationManager extends RelationManager
{
    protected static string $relationship = 'deals';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('deals.navigation.plural_model');
    }

    public static function getModelLabel(): string
    {
        return __('deals.navigation.model');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Deal::class);
    }

    public function form(Schema $schema): Schema
    {
        return DealForm::configure($schema, withAccount: false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereIn('deals.id', DealResource::getEloquentQuery()->select('deals.id'))
                ->with(['stage', 'owner']))
            ->columns([
                TextColumn::make('title')
                    ->label(__('deals.fields.title'))
                    ->searchable()
                    ->weight('semibold')
                    ->url(fn (Deal $record): string => DealResource::getUrl('view', ['record' => $record])),
                TextColumn::make('stage.display_name')
                    ->label(__('deals.fields.stage'))
                    ->badge()
                    ->color(fn (Deal $record): string => (string) ($record->stage?->color->value ?? 'gray')),
                TextColumn::make('status')
                    ->label(__('deals.fields.status'))
                    ->badge(),
                LtrText::column(
                    TextColumn::make('amount')
                        ->label(__('deals.fields.amount'))
                        ->money(currency: fn (): string => DealResource::currency(), locale: fn (): string => app()->getLocale())
                        ->sortable(),
                ),
                TextColumn::make('owner.name')
                    ->label(__('deals.fields.owner'))
                    ->placeholder(__('assignment.placeholders.unassigned')),
                TextColumn::make('expected_close_date')
                    ->label(__('deals.fields.expected_close_date'))
                    ->date('Y-m-d')
                    ->color(fn (Deal $record): ?string => DealInfolist::isOverdue($record) ? 'danger' : null)
                    ->placeholder(__('common.placeholders.empty'))
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                // The create form is the deal form, custom section included, so
                // its values are held aside and written once the row has an id
                // (D-9).
                CustomFieldActions::createAction(
                    CreateAction::make(),
                    mutate: function (array $data): array {
                        $data['created_by'] = auth()->id();
                        $data['owner_id'] = $data['owner_id'] ?? auth()->id();
                        $data['currency'] = DealResource::currency();
                        $data['pipeline_id'] = $data['pipeline_id'] ?? DealForm::defaultPipelineId();
                        $data['stage_id'] = $data['stage_id'] ?? DealForm::defaultStageId($data['pipeline_id']);

                        return $data;
                    },
                    after: fn (Deal $record) => app(DealAmountCalculator::class)->recalculate($record),
                ),
            ])
            ->recordActions([
                ViewAction::make()->url(fn (Deal $record): string => DealResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading(__('deals.empty.heading'))
            ->emptyStateDescription(__('deals.empty.description'));
    }
}
