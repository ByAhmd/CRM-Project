<?php

declare(strict_types=1);

namespace App\Services\ImportExport;

/**
 * What undoing an import run would do, or what one batch of the undo did
 * (decision D-29): the records the run created, each counted once under the
 * first reason that applies — already deleted, touched since the run,
 * outside what the actor may delete, or deletable — plus the duplicates the
 * run updated rather than created, which never carry the run's id and are
 * counted from the run's successful rows.
 *
 * Built by ImportUndoService::preview() for the confirmation dialog and by
 * each batch of the queued job, which deletes exactly `deletableIds`.
 */
final readonly class ImportUndoPreview
{
    /**
     * @param  int  $deletable  created, untouched, within the actor's delete reach — these are deleted
     * @param  int  $updated  rows the run matched to an existing record and updated; never deleted
     * @param  int  $touched  created, but changed or worked on after the run completed
     * @param  int  $forbidden  created, but the entity's `delete` policy refuses the actor
     * @param  int  $alreadyDeleted  created and soft-deleted since, by anyone
     * @param  int  $total  every record carrying the run's id, deleted ones included
     * @param  list<int>  $deletableIds  the keys behind `$deletable`, in key order
     */
    public function __construct(
        public int $deletable,
        public int $updated,
        public int $touched,
        public int $forbidden,
        public int $alreadyDeleted,
        public int $total,
        public array $deletableIds,
    ) {}

    /** A run that created nothing (or whose importer is not one of ours). */
    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, 0, []);
    }

    /** Everything the undo leaves in place, for whatever reason. */
    public function kept(): int
    {
        return $this->updated + $this->touched + $this->forbidden + $this->alreadyDeleted;
    }

    public function hasNothingToDelete(): bool
    {
        return $this->deletable === 0;
    }
}
