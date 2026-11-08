<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Filament\Support\ImportExportActions;
use App\Models\Project;
use Filament\Actions\Imports\Events\ImportStarted;

/**
 * Stamps on an import's history row what the modal asked for, the moment
 * Filament starts the run: the check-only flag (decision D-29) and the
 * project the run writes into (decision D-30).
 *
 * Why a listener: Filament's ImportAction instantiates and saves the Import
 * row itself, inside its own action closure, and the modal's options are
 * never handed to anything of ours together with that row — the importer
 * sees the options per row (ResolvesImportLookups::isDryRun(),
 * chosenProject()) but not the row's creation, and no model hook sees the
 * options at all. The one place both are in hand before the batch is
 * dispatched is the ImportStarted event Filament fires right after the save.
 * The values must sit on the row because everything that happens after the
 * rows are processed reads the row, not the options: the completion notice's
 * title and body, the «check only» and project badges in the history,
 * ImportPolicy::undo() and the undo service.
 *
 * The values are saved, not merely set: the chunk jobs (SerializesModels) and
 * the batch's finally callback (a SerializableClosure, whose models Laravel
 * also serialises by id) are restored from the table on every connection, the
 * sync one included, so the queued rows, the completion notice, the history
 * and ImportPolicy::undo all read `imports.dry_run` and `imports.project_id`
 * from the row.
 *
 * The project is stamped when the option names a project that exists —
 * active or not, so the history says which project a run asked for even
 * when the importer then refuses every row for an inactive one. An option
 * naming no project leaves the column null (runs from before D-30, and the
 * direct ImportCsv path without the modal, where the importer falls back to
 * the importing user's current project).
 *
 * Discovered from app/Listeners like every listener here (AppServiceProvider
 * boot()); a second, explicit registration would run it twice per import.
 */
final class StampImportRunOptions
{
    public function handle(ImportStarted $event): void
    {
        $options = $event->getOptions();
        $attributes = [];

        if ((bool) ($options[ImportExportActions::DRY_RUN_OPTION] ?? false)) {
            $attributes['dry_run'] = true;
        }

        $projectId = self::projectId($options[ImportExportActions::PROJECT_OPTION] ?? null);

        if ($projectId !== null && Project::query()->whereKey($projectId)->exists()) {
            $attributes['project_id'] = $projectId;
        }

        if ($attributes === []) {
            return;
        }

        $event->getImport()->forceFill($attributes)->save();
    }

    /** The option as an id: the select sends the key as an int or a digit string. */
    private static function projectId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
