<?php

declare(strict_types=1);

namespace App\Filament\Exports\Concerns;

use App\Filament\Support\ImportExportActions;
use Filament\Actions\Exports\Models\Export;

/**
 * The completion notice's title in the run's locale (D-5, A-7 amendment of
 * 2026-10-25).
 *
 * Every exporter renders its rows and its completion body in the locale the
 * run was started in (ImportExportActions::applyLocale). Filament builds the
 * notice in ExportCompletion, a job chained AFTER the export batch, and reads
 * the title before the body: a drain cut by its 50-second cap between the
 * batch and that job (D-1) hands the job to the next minute's process, which
 * starts in the application's default locale, so the title would read in
 * Arabic over an English body. The title therefore applies the run's locale
 * itself, exactly as the body does.
 */
trait LocalisesCompletionNotice
{
    public static function getCompletedNotificationTitle(Export $export): string
    {
        ImportExportActions::applyLocale($export->getOptions());

        return parent::getCompletedNotificationTitle($export);
    }
}
