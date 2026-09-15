<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Enums\ActivityLogEvent;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Audit\ActivityLogPresenter;
use App\Services\Settings\SettingsRepository;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared ledger table: the system audit screen and, later, per-record history tabs.
 */
final class ActivityLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('activity.fields.recorded_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('log_name')
                    ->label(__('activity.fields.area'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (ActivityLog $record): string => app(ActivityLogPresenter::class)->for($record)->logNameLabel()),

                TextColumn::make('description')
                    ->label(__('activity.fields.action'))
                    ->formatStateUsing(fn (ActivityLog $record): string => app(ActivityLogPresenter::class)->for($record)->actionLabel())
                    ->wrap(),

                TextColumn::make('subject')
                    ->label(__('activity.fields.subject'))
                    ->state(fn (ActivityLog $record): string => app(ActivityLogPresenter::class)->for($record)->subjectLabel())
                    ->wrap(),

                TextColumn::make('causer')
                    ->label(__('activity.fields.causer'))
                    ->state(fn (ActivityLog $record): string => app(ActivityLogPresenter::class)->for($record)->causerLabel()),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('date_range')
                    ->label(__('activity.filters.date_range'))
                    ->schema([
                        DatePicker::make('from')->label(__('activity.filters.from'))->native(false),
                        DatePicker::make('until')->label(__('activity.filters.until'))->native(false),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(filled($data['from'] ?? null), fn (Builder $nested): Builder => $nested->where('created_at', '>=', app(SettingsRepository::class)->startOfOrganisationDay((string) $data['from'])))
                            ->when(filled($data['until'] ?? null), fn (Builder $nested): Builder => $nested->where('created_at', '<=', app(SettingsRepository::class)->endOfOrganisationDay((string) $data['until'])));
                    }),

                SelectFilter::make('log_name')
                    ->label(__('activity.filters.area'))
                    ->options(self::areaOptions())
                    ->multiple(),

                SelectFilter::make('description')
                    ->label(__('activity.filters.event'))
                    ->options(self::eventOptions())
                    ->searchable()
                    ->multiple(),

                SelectFilter::make('causer_id')
                    ->label(__('activity.filters.causer'))
                    ->options(fn (): array => User::withTrashed()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === null || $value === '') {
                            return $query;
                        }

                        return $query
                            ->where('causer_type', (new User)->getMorphClass())
                            ->where('causer_id', $value);
                    }),
            ])
            ->recordActions([
                Action::make('details')
                    ->label(__('activity.actions.details'))
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->authorize(fn (ActivityLog $record): bool => auth()->user()?->can('view', $record) ?? false)
                    ->modalHeading(__('activity.details.heading'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('activity.actions.close'))
                    ->modalContent(fn (ActivityLog $record) => view('filament.activity-log.details', [
                        'rows' => app(ActivityLogPresenter::class)->for($record)->detailRows(),
                    ])),
            ])
            ->emptyStateHeading(__('activity.empty.heading'))
            ->emptyStateDescription(__('activity.empty.description'));
    }

    /**
     * @return array<string, string>
     */
    private static function areaOptions(): array
    {
        $options = [];

        foreach (ActivityLogEvent::logNames() as $logName) {
            $options[$logName] = __('activity.log_names.'.$logName);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function eventOptions(): array
    {
        $options = [];

        foreach (ActivityLogEvent::cases() as $event) {
            $options[$event->value] = $event->getLabel();
        }

        return $options;
    }
}
