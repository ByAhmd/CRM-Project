<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Filament\Support\ImportExportActions;
use Filament\Actions\Imports\Events\ImportStarted;

/**
 * Marks a check-only run on its history row the moment Filament starts it
 * (decision D-29).
 *
 * Why a listener: Filament's ImportAction instantiates and saves the Import
 * row itself, inside its own action closure, and the modal's options are
 * never handed to anything of ours together with that row — the importer
 * sees the option per row (ResolvesImportLookups::isDryRun()) but not the
 * row's creation, and no model hook sees the options at all. The one place
 * both are in hand before the batch is dispatched is the ImportStarted event
 * Filament fires right after the save. The flag must sit on the row because
 * everything that happens after the rows are processed reads the row, not
 * the options: the completion notice's title and body, the «check only»
 * badge in the history, ImportPolicy::undo() and the undo service.
 *
 * The flag is saved, not merely set: the chunk jobs (SerializesModels) and the
 * batch's finally callback (a SerializableClosure, whose models Laravel also
 * serialises by id) are restored from the table on every connection, the sync
 * one included, so the queued rows, the completion notice, the history badge
 * and ImportPolicy::undo all read `imports.dry_run` from the row.
 *
 * Discovered from app/Listeners like every listener here (AppServiceProvider
 * boot()); a second, explicit registration would run it twice per import.
 */
final class StampCheckOnlyImport
{
    public function handle(ImportStarted $event): void
    {
        if (! (bool) ($event->getOptions()[ImportExportActions::DRY_RUN_OPTION] ?? false)) {
            return;
        }

        $event->getImport()->forceFill(['dry_run' => true])->save();
    }
}
