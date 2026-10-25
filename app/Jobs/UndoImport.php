<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Filament\Support\ImportExportActions;
use App\Models\Import;
use App\Models\User;
use App\Services\ImportExport\ImportUndoService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Undoes one batch of an import run (decision D-29): the records the run
 * created after the batch cursor, up to ImportUndoService::BATCH_SIZE of
 * them, classified again now and soft-deleted where allowed, then the next
 * batch is queued or the run is finalised (ImportUndoService::undoBatch).
 *
 * Queued by ImportUndoService::request once the actor's confirmation has
 * committed, and by each batch for the next: the scheduler drains the queue
 * in short runs on the shared host (D-1), so no single job may outlive the
 * drain window however many records the run created. The unique id is the
 * run plus the batch cursor, so a confirmation is never queued twice while a
 * run's continuation is never mistaken for a duplicate.
 *
 * The job carries the locale the actor confirmed in and switches the worker
 * to it before the batch, the way the importers do (D-5), so the final bell
 * notice reads in the actor's language whatever locale the console drain
 * started in. A run pruned by retention or an actor whose account was
 * deleted in the meantime ends the job quietly: there is no one to act for.
 */
final class UndoImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * 45 seconds: inside the scheduler drain's queue:work --max-time=50
     * window (routes/console.php) and well under the database queue's
     * retry_after (90), so a slow batch is killed before it could be
     * released to a second worker — the same budget as RescoreLeads.
     */
    public int $timeout = 45;

    /**
     * @param  array{deleted?: int, touched?: int, forbidden?: int, already_deleted?: int}  $carried  the tallies of the batches before this one
     */
    public function __construct(
        public readonly int $importId,
        public readonly int $actorId,
        public readonly string $locale,
        public readonly int $afterId = 0,
        public readonly array $carried = [],
    ) {}

    public function uniqueId(): string
    {
        return $this->importId.':'.$this->afterId;
    }

    public function handle(ImportUndoService $undo): void
    {
        $import = Import::query()->find($this->importId);
        $actor = User::query()->find($this->actorId);

        if (! $import instanceof Import || ! $actor instanceof User) {
            return;
        }

        ImportExportActions::applyLocale([ImportExportActions::LOCALE_OPTION => $this->locale]);

        $undo->undoBatch($import, $actor, $this->afterId, $this->carried);
    }
}
