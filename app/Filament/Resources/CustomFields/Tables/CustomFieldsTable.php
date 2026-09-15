<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomFields\Tables;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Exceptions\CustomFields\InvalidCustomFieldException;
use App\Models\CustomField;
use App\Services\CustomFields\CustomFieldService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The definitions list, grouped by entity and ordered the way the fields are
 * presented on the record (decision D-9).
 *
 * Reordering writes the `sort` column of the dragged rows. Filament reorders
 * the visible table as one list, so an administrator who wants to reorder one
 * entity filters the list to it first; sorting the whole list by entity keeps
 * the groups together in the meantime. Filament's own write numbers the whole
 * list, which is meaningless across entities, so the service renumbers each
 * entity's dragged rows from zero right after it — the service stays the one
 * place that decides what `sort` means (D-9).
 *
 * The delete action is disabled — and unauthorised — for a definition that
 * holds values: those rows would cascade away with it (D-13).
 */
final class CustomFieldsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('entity')
                    ->label(__('custom_fields.fields.entity'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('key')
                    ->label(__('custom_fields.fields.key'))
                    ->extraCellAttributes(['dir' => 'ltr'])
                    ->searchable()
                    ->sortable(),

                TextColumn::make('display_label')
                    ->label(__('custom_fields.fields.label'))
                    ->state(fn (CustomField $record): string => $record->display_label)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('label_ar', 'like', "%{$search}%")
                        ->orWhere('label_en', 'like', "%{$search}%")),

                TextColumn::make('type')
                    ->label(__('custom_fields.fields.type'))
                    ->badge()
                    ->sortable(),

                IconColumn::make('is_required')
                    ->label(__('custom_fields.fields.is_required'))
                    ->boolean(),

                IconColumn::make('is_listed')
                    ->label(__('custom_fields.fields.is_listed'))
                    ->boolean()
                    ->toggleable(),

                IconColumn::make('is_filterable')
                    ->label(__('custom_fields.fields.is_filterable'))
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('values_count')
                    ->label(__('custom_fields.fields.values_count'))
                    ->counts('values')
                    ->numeric()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('custom_fields.fields.is_active'))
                    ->boolean(),

                TextColumn::make('sort')
                    ->label(__('custom_fields.fields.sort'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(fn (Builder $query, string $direction): Builder => $query
                ->orderBy('entity', $direction)
                ->orderBy('sort', $direction))
            ->reorderable('sort')
            ->afterReordering(static function (array $order): void {
                app(CustomFieldService::class)->reorderAcross($order);
            })
            ->filters([
                SelectFilter::make('entity')
                    ->label(__('custom_fields.filters.entity'))
                    ->options(CustomFieldEntity::class),

                SelectFilter::make('type')
                    ->label(__('custom_fields.filters.type'))
                    ->options(CustomFieldType::class)
                    ->multiple(),

                TernaryFilter::make('is_active')->label(__('custom_fields.filters.is_active')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->successNotificationTitle(__('custom_fields.notifications.deleted'))
                    ->authorize(fn (CustomField $record): bool => auth()->user()?->can('delete', $record) ?? false)
                    ->disabled(fn (CustomField $record): bool => ! app(CustomFieldService::class)->isDeletable($record))
                    ->tooltip(fn (CustomField $record): ?string => app(CustomFieldService::class)->isDeletable($record)
                        ? null
                        : __('custom_fields.helpers.delete_blocked'))
                    ->using(function (CustomField $record): bool {
                        try {
                            app(CustomFieldService::class)->delete($record);
                        } catch (InvalidCustomFieldException $exception) {
                            Notification::make()
                                ->title($exception->getMessage())
                                ->danger()
                                ->send();

                            throw new Halt;
                        }

                        return true;
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->emptyStateHeading(__('custom_fields.empty.heading'))
            ->emptyStateDescription(__('custom_fields.empty.description'));
    }
}
