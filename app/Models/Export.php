<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Actions\Exports\Models\Export as FilamentExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * An export run (module 18, decisions D-1, D-13).
 *
 * Filament's ExportAction writes the row through its own model class; this
 * subclass reads the same table and exists so the application can register
 * ExportPolicy against it and point ExportResource at it. It adds nothing to
 * the row shape.
 *
 * The export files live on the private `local` disk under
 * filament_exports/{id}; Exporter::getFileDisk() never returns the public
 * disk. Filament leaves `prunable()` unimplemented, so the model defines the
 * window and removes the file directory before the row is pruned.
 *
 * @property ?Carbon $completed_at
 * @property-read User $user
 */
final class Export extends FilamentExport
{
    public const RETENTION_DAYS = 30;

    /**
     * Whether the files are on the disk, memoised per instance: the exports
     * table asks for every row twice per render (one download action per
     * format) and the answer cannot change within a request.
     */
    private ?bool $hasFile = null;

    /**
     * Filament casts completed_at to a unix timestamp; the panel formats it
     * as a date, so it is read as one here.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<=', now()->subDays(self::RETENTION_DAYS));
    }

    /** The generated files go with the row. */
    protected function pruning(): void
    {
        $this->deleteFileDirectory();
    }

    /** The user who launched the run. */
    public function isOwnedBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->getKey();
    }

    /** Whether the generated files are still on the disk (download offered only then). */
    public function hasFile(): bool
    {
        if ($this->completed_at === null || blank($this->file_name)) {
            return false;
        }

        return $this->hasFile ??= $this->getFileDisk()->directoryExists($this->getFileDirectory());
    }
}
