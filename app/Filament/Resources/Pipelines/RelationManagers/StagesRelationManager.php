<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pipelines\RelationManagers;

use App\Exceptions\Settings\InvalidPipelineException;
use App\Filament\Resources\Pipelines\Schemas\PipelineStageForm;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\Settings\PipelineService;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The stages of one pipeline, edited in place on the pipeline's edit page
 * (decision D-8). Every create, edit and delete goes through PipelineService
 * so the stage-set invariants hold after each change; a refusal is shown as
 * a danger notification and the action halts.
 */
final class StagesRelationManager extends RelationManager
{
    protected static string $relationship = 'stages';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('pipelines.stages.navigation.label');
    }

    public function form(Schema $schema): Schema
    {
        $pipeline = $this->getOwnerRecord();
        assert($pipeline instanceof Pipeline);

        return PipelineStageForm::configure($schema, $pipeline);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('pipelines.stages.navigation.model'))
            ->pluralModelLabel(__('pipelines.stages.navigation.plural_model'))
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('pipelines.stages.fields.name'))
                    ->state(fn (PipelineStage $record): string => $record->display_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $query): Builder => $query
                            ->where('name_ar', 'like', "%{$search}%")
                            ->orWhere('name_en', 'like', "%{$search}%")))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(PipelineStage::localisedNameColumn(), $direction)),

                TextColumn::make('kind')
                    ->label(__('pipelines.stages.fields.kind'))
                    ->badge(),

                TextColumn::make('probability')
                    ->label(__('pipelines.stages.fields.probability'))
                    ->numeric()
                    ->suffix('%')
                    ->sortable(),

                // Phone budget: the colour swatch is the one column a narrow
                // screen can spare — name, kind, probability and the default
                // flag stay.
                TextColumn::make('color')
                    ->label(__('pipelines.stages.fields.color'))
                    ->badge()
                    ->color(fn (PipelineStage $record): string => $record->color->value)
                    ->visibleFrom('md'),

                IconColumn::make('is_default')
                    ->label(__('pipelines.stages.fields.is_default'))
                    ->boolean(),

                TextColumn::make('sort')
                    ->label(__('pipelines.stages.fields.sort'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->headerActions([
                CreateAction::make()
                    ->using(function (array $data): PipelineStage {
                        $pipeline = $this->getOwnerRecord();
                        assert($pipeline instanceof Pipeline);

                        try {
                            return app(PipelineService::class)->createStage($pipeline, $data);
                        } catch (InvalidPipelineException $exception) {
                            $this->refuse($exception);
                        }
                    }),
            ])
            // One three-dot menu per row instead of a wall of links; every
            // action keeps its own authorisation, and the menu hides itself
            // when the policy refuses every action in it.
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->using(function (PipelineStage $record, array $data): PipelineStage {
                            try {
                                return app(PipelineService::class)->updateStage($record, $data);
                            } catch (InvalidPipelineException $exception) {
                                $this->refuse($exception);
                            }
                        }),
                    DeleteAction::make()
                        ->hidden(fn (PipelineStage $record): bool => ! app(PipelineService::class)->isStageDeletable($record))
                        ->using(function (PipelineStage $record): bool {
                            try {
                                app(PipelineService::class)->deleteStage($record);
                            } catch (InvalidPipelineException $exception) {
                                $this->refuse($exception);
                            }

                            return true;
                        }),
                ])
                    ->label(__('app.actions.row_actions'))
                    ->tooltip(__('app.actions.row_actions')),
            ])
            ->emptyStateHeading(__('pipelines.stages.empty.heading'))
            ->emptyStateDescription(__('pipelines.stages.empty.description'));
    }

    private function refuse(InvalidPipelineException $exception): never
    {
        Notification::make()
            ->title($exception->getMessage())
            ->danger()
            ->send();

        throw new Halt;
    }
}
