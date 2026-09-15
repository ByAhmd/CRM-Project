<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activities\Tables;

use App\Enums\ActivityKind;
use App\Filament\Exports\ActivityExporter;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Support\ImportExportActions;
use App\Filament\Support\SubjectPickers;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Activities\ActivityRecorder;
use App\Services\Settings\SettingsRepository;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The activity listing (decision A-10), shared by the resource and the
 * relation managers on the four subject records. On a relation manager
 * the subject is the owner record, so the subject column is left out.
 */
final class ActivitiesTable
{
    public static function configure(Table $table, bool $withSubject = true): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('activities.fields.occurred_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('kind')
                    ->label(__('activities.fields.kind'))
                    ->badge(),

                TextColumn::make('type.display_name')
                    ->label(__('activities.fields.type'))
                    ->toggleable(),

                TextColumn::make('subject')
                    ->label(__('activities.fields.subject'))
                    ->searchable()
                    ->weight('semibold')
                    ->limit(60),

                ...$withSubject ? [
                    TextColumn::make('subject_record')
                        ->label(__('activities.fields.related'))
                        ->state(fn (Activity $record): ?string => $record->subjectLabel())
                        ->url(function (Activity $record): ?string {
                            $subject = $record->subjectRecord();

                            return $subject === null ? null : ActivityResource::urlForSubject($subject);
                        })
                        ->placeholder(__('common.placeholders.empty')),
                ] : [],

                TextColumn::make('direction')
                    ->label(__('activities.fields.direction'))
                    ->badge()
                    ->color('gray')
                    ->placeholder(__('common.placeholders.empty'))
                    ->toggleable(),

                TextColumn::make('duration_minutes')
                    ->label(__('activities.fields.duration_minutes'))
                    ->numeric()
                    ->placeholder(__('common.placeholders.empty'))
                    ->toggleable(),

                TextColumn::make('owner.name')
                    ->label(__('activities.fields.owner'))
                    ->placeholder(__('assignment.placeholders.unassigned'))
                    ->sortable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('activities.filters.kind'))
                    ->options(ActivityKind::class)
                    ->multiple(),

                SelectFilter::make('activity_type_id')
                    ->label(__('activities.filters.type'))
                    ->relationship('type', ActivityType::localisedNameColumn())
                    ->getOptionLabelFromRecordUsing(fn (Model $record): string => (string) $record->getAttribute('display_name'))
                    ->multiple()
                    ->preload(),

                SelectFilter::make('owner_id')
                    ->label(__('activities.filters.owner'))
                    ->options(function (): array {
                        $user = auth()->user();

                        return $user instanceof User
                            ? app(RecordVisibilityResolver::class)->assignableUsers($user, Activity::permissionGroup())->pluck('name', 'id')->all()
                            : [];
                    })
                    ->searchable(),

                Filter::make('occurred_at')
                    ->schema([
                        DatePicker::make('from')->label(__('activities.filters.occurred_from'))->native(false),
                        DatePicker::make('until')->label(__('activities.filters.occurred_until'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['from'] ?? null), fn (Builder $query): Builder => $query->where('occurred_at', '>=', app(SettingsRepository::class)->startOfOrganisationDay((string) $data['from'])))
                        ->when(filled($data['until'] ?? null), fn (Builder $query): Builder => $query->where('occurred_at', '<=', app(SettingsRepository::class)->endOfOrganisationDay((string) $data['until']))))
                    ->indicateUsing(fn (array $data): array => array_filter([
                        filled($data['from'] ?? null) ? __('activities.filters.occurred_from').': '.$data['from'] : null,
                        filled($data['until'] ?? null) ? __('activities.filters.occurred_until').': '.$data['until'] : null,
                    ])),

                ...$withSubject ? [
                    SelectFilter::make('subject')
                        ->label(__('activities.filters.subject'))
                        ->options([
                            'lead_id' => __('activities.fields.lead'),
                            'contact_id' => __('activities.fields.contact'),
                            'account_id' => __('activities.fields.account'),
                            'deal_id' => __('activities.fields.deal'),
                        ])
                        ->query(function (Builder $query, array $data): Builder {
                            $column = $data['value'] ?? null;

                            return is_string($column) && in_array($column, SubjectPickers::COLUMNS, true)
                                ? $query->whereNotNull($column)
                                : $query;
                        }),
                ] : [],
            ])
            ->recordActions([
                ViewAction::make(),
                self::deleteAction(),
            ])
            ->toolbarActions([
                ...$withSubject ? [ImportExportActions::export(ActivityExporter::class, Activity::class)] : [],
                BulkActionGroup::make([
                    ...$withSubject ? [ImportExportActions::exportBulk(ActivityExporter::class, Activity::class)] : [],
                    self::deleteBulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('activities.empty.heading'))
            ->emptyStateDescription(__('activities.empty.description'));
    }

    /**
     * Hard delete through the recorder so the removal is audited (A-10).
     * Authorised per record by ActivityPolicy::delete.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label(__('activities.actions.delete'))
            ->authorize(fn (Activity $record): bool => auth()->user()?->can('delete', $record) ?? false)
            ->using(function (Activity $record): bool {
                $actor = auth()->user();
                assert($actor instanceof User);

                app(ActivityRecorder::class)->delete($record, $actor);

                return true;
            })
            ->successNotificationTitle(__('activities.notifications.deleted'));
    }

    public static function deleteBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->authorizeIndividualRecords('delete')
            ->using(function (Collection $records): void {
                $actor = auth()->user();
                assert($actor instanceof User);

                foreach ($records as $record) {
                    if ($record instanceof Activity) {
                        app(ActivityRecorder::class)->delete($record, $actor);
                    }
                }
            });
    }
}
