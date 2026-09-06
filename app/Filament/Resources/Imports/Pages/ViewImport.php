<?php

declare(strict_types=1);

namespace App\Filament\Resources\Imports\Pages;

use App\Filament\Resources\Imports\ImportResource;
use App\Models\Import;
use Filament\Actions\Action;
use Filament\Actions\Imports\Http\Controllers\DownloadImportFailureCsv;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One import run: the counts, the failed rows and their CSV.
 *
 * The failed-rows file is streamed by Filament's own controller, called
 * with this (policy-bearing) model so ImportPolicy::view decides — the
 * owner always, `imports.view` holders for every run. The signed route
 * Filament puts in the completion notification stays owner-only.
 */
final class ViewImport extends ViewRecord
{
    protected static string $resource = ImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadFailedRows')
                ->label(__('imports.actions.download_failed_rows'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('danger')
                ->visible(fn (Import $record): bool => $record->getFailedRowsCount() > 0)
                ->authorize(fn (Import $record): bool => auth()->user()?->can('view', $record) ?? false)
                ->action(fn (Import $record): StreamedResponse => app(DownloadImportFailureCsv::class)(request(), $record)),
        ];
    }
}
