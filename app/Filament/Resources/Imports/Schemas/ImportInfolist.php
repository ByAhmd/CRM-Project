<?php

declare(strict_types=1);

namespace App\Filament\Resources\Imports\Schemas;

use App\Filament\Resources\Imports\ImportResource;
use App\Models\Import;
use Filament\Actions\Imports\Models\FailedImportRow;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One import run: the summary, the counts and the first failed rows with
 * the reason each was refused (the CSV of every failed row is the page's
 * download action).
 */
final class ImportInfolist
{
    /** Failed rows shown inline; the full list is in the downloadable CSV. */
    public const FAILED_ROWS_SHOWN = 200;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('imports.sections.summary'))
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('file_name')
                                ->label(__('imports.fields.file_name'))
                                ->extraAttributes(['dir' => 'ltr']),
                            TextEntry::make('importer')
                                ->label(__('imports.fields.entity'))
                                ->formatStateUsing(fn (string $state): string => ImportResource::entityLabel($state))
                                ->badge()
                                ->color('gray'),
                            TextEntry::make('user.name')->label(__('imports.fields.user'))->placeholder(__('common.placeholders.empty')),
                            TextEntry::make('status')
                                ->label(__('imports.fields.status'))
                                ->state(fn (Import $record): string => ImportResource::status($record))
                                ->formatStateUsing(fn (string $state): string => __('imports.statuses.'.$state))
                                ->badge()
                                ->color(fn (string $state): string => ImportResource::statusColor($state)),
                            TextEntry::make('created_at')->label(__('imports.fields.created_at'))->dateTime('Y-m-d H:i'),
                            TextEntry::make('completed_at')->label(__('imports.fields.completed_at'))->dateTime('Y-m-d H:i')->placeholder(__('common.placeholders.empty')),
                        ]),
                        Grid::make(4)->schema([
                            TextEntry::make('total_rows')->label(__('imports.fields.total_rows'))->numeric(),
                            TextEntry::make('processed_rows')->label(__('imports.fields.processed_rows'))->numeric(),
                            TextEntry::make('successful_rows')->label(__('imports.fields.successful_rows'))->numeric()->color('success'),
                            TextEntry::make('failed_rows_count')
                                ->label(__('imports.fields.failed_rows'))
                                ->state(fn (Import $record): int => $record->getFailedRowsCount())
                                ->numeric()
                                ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
                        ]),
                    ])
                    ->columns(1),

                Section::make(__('imports.sections.failed_rows'))
                    ->description(__('imports.helpers.failed_rows', ['limit' => (string) self::FAILED_ROWS_SHOWN]))
                    ->visible(fn (Import $record): bool => $record->getFailedRowsCount() > 0)
                    ->schema([
                        RepeatableEntry::make('failed_rows')
                            ->hiddenLabel()
                            ->state(fn (Import $record): array => self::failedRows($record))
                            ->schema([
                                TextEntry::make('row')->label(__('imports.fields.row_data'))->placeholder(__('common.placeholders.empty')),
                                TextEntry::make('error')->label(__('imports.fields.error'))->color('danger')->placeholder(__('common.placeholders.empty')),
                            ])
                            ->columns(1),
                    ])
                    ->columns(1)
                    ->collapsible(),
            ])
            ->columns(1);
    }

    /**
     * The first failed rows as "column: value" lines with their reason.
     *
     * @return list<array{row: string, error: ?string}>
     */
    private static function failedRows(Import $record): array
    {
        return FailedImportRow::query()
            ->where('import_id', $record->getKey())
            ->orderBy('id')
            ->limit(self::FAILED_ROWS_SHOWN)
            ->get()
            ->map(fn (FailedImportRow $row): array => [
                'row' => collect($row->data)
                    ->map(fn (mixed $value, string $column): string => $column.': '.(is_scalar($value) ? (string) $value : ''))
                    ->implode(' | '),
                'error' => $row->validation_error,
            ])
            ->all();
    }
}
