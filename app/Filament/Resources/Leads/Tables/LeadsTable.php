<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Tables;

use App\Enums\CustomFieldEntity;
use App\Enums\LeadPriority;
use App\Filament\Exports\LeadExporter;
use App\Filament\Resources\Leads\Schemas\LeadInfolist;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\EmailActions;
use App\Filament\Support\ImportExportActions;
use App\Filament\Support\LeadActions;
use App\Filament\Support\LeadConversionActions;
use App\Filament\Support\LtrText;
use App\Filament\Support\OwnershipActions;
use App\Filament\Support\QueryBuilderFilters;
use App\Filament\Support\TagsSelect;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Every listed custom column reads the record's values, so the
            // relation is loaded once for the page instead of per cell (D-9).
            ->modifyQueryUsing(fn (Builder $query): Builder => CustomFieldsSchema::eagerLoad($query))
            ->columns([
                TextColumn::make('full_name')
                    ->label(__('leads.fields.name'))
                    ->state(fn (Lead $record): string => $record->full_name)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('last_name', $direction)->orderBy('first_name', $direction))
                    ->weight('semibold'),

                TextColumn::make('company_name')
                    ->label(__('leads.fields.company_name'))
                    ->searchable()
                    ->placeholder(__('common.placeholders.empty'))
                    ->toggleable(),

                TextColumn::make('status.display_name')
                    ->label(__('leads.fields.status'))
                    ->badge()
                    ->color(fn (Lead $record): string => (string) ($record->status?->color->value ?? 'gray')),

                TextColumn::make('effective_score')
                    ->label(__('leads.fields.score'))
                    ->state(fn (Lead $record): int => $record->effective_score)
                    ->badge()
                    ->color(fn (Lead $record): string => LeadInfolist::scoreColor($record->effective_score))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('COALESCE(score_override, score) '.($direction === 'desc' ? 'DESC' : 'ASC'))),

                TextColumn::make('priority')
                    ->label(__('leads.fields.priority'))
                    ->badge()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('source.display_name')
                    ->label(__('leads.fields.source'))
                    ->placeholder(__('common.placeholders.empty'))
                    ->toggleable(),

                TextColumn::make('owner.name')
                    ->label(__('leads.fields.owner'))
                    ->placeholder(__('assignment.placeholders.unassigned'))
                    ->sortable(),

                LtrText::column(
                    TextColumn::make('phone')
                        ->label(__('leads.fields.phone'))
                        ->searchable()
                        ->placeholder(__('common.placeholders.empty'))
                        ->toggleable(isToggledHiddenByDefault: true),
                ),

                LtrText::column(
                    TextColumn::make('email')
                        ->label(__('leads.fields.email'))
                        ->searchable()
                        ->placeholder(__('common.placeholders.empty'))
                        ->toggleable(isToggledHiddenByDefault: true),
                ),

                TagsSelect::column(),

                TextColumn::make('created_at')
                    ->label(__('leads.fields.created_at'))
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(),

                ...CustomFieldsSchema::tableColumns(CustomFieldEntity::Lead),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('lead_status_id')
                    ->label(__('leads.filters.status'))
                    ->relationship('status', LeadStatus::localisedNameColumn())
                    ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) $record->getAttribute('display_name'))
                    ->multiple()
                    ->preload(),

                SelectFilter::make('lead_source_id')
                    ->label(__('leads.filters.source'))
                    ->relationship('source', LeadSource::localisedNameColumn())
                    ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) $record->getAttribute('display_name'))
                    ->multiple()
                    ->preload(),

                SelectFilter::make('priority')
                    ->label(__('leads.filters.priority'))
                    ->options(LeadPriority::class)
                    ->multiple(),

                SelectFilter::make('owner_id')
                    ->label(__('leads.filters.owner'))
                    ->options(function (): array {
                        $user = auth()->user();

                        return $user instanceof User
                            ? app(RecordVisibilityResolver::class)->assignableUsers($user, Lead::permissionGroup())->pluck('name', 'id')->all()
                            : [];
                    })
                    ->searchable(),

                TagsSelect::filter(),

                TrashedFilter::make()->label(__('leads.filters.trashed')),

                QueryBuilderFilters::forLeads(),

                ...CustomFieldsSchema::tableFilters(CustomFieldEntity::Lead),
            ])
            ->filtersLayout(QueryBuilderFilters::layout())
            ->filtersFormWidth(QueryBuilderFilters::width())
            // One three-dot menu per row instead of a wall of links; every
            // action keeps its own authorisation, and the menu hides itself
            // when the policy refuses every action in it.
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    // The custom section is part of the entity form, so an edit
                    // modal built from it pre-fills and writes the values too (D-9).
                    CustomFieldActions::editAction(EditAction::make()),
                    LeadActions::changeStatus(),
                    LeadConversionActions::convert(),
                    EmailActions::send(),
                    OwnershipActions::assign(Lead::permissionGroup()),
                ])
                    ->label(__('app.actions.row_actions'))
                    ->tooltip(__('app.actions.row_actions')),
            ])
            ->toolbarActions([
                ImportExportActions::export(LeadExporter::class, Lead::class),
                BulkActionGroup::make([
                    ImportExportActions::exportBulk(LeadExporter::class, Lead::class),
                    LeadActions::changeStatusBulk(),
                    OwnershipActions::assignBulk(Lead::permissionGroup()),
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                    RestoreBulkAction::make()->authorizeIndividualRecords('restore'),
                ]),
            ])
            ->emptyStateHeading(__('leads.empty.heading'))
            ->emptyStateDescription(__('leads.empty.description'));
    }
}
