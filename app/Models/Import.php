<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Actions\Imports\Models\Import as FilamentImport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An import run (module 18, decisions D-1, D-29).
 *
 * Filament's ImportAction writes the row through its own model class; this
 * subclass reads the same table and exists so the application can register
 * ImportPolicy against it (Laravel maps policies by model class) and point
 * ImportResource at it. Filament's #[Fillable]-less `$guarded = []` stays as
 * inherited: the row is written by Filament's action and by ImportUndoService
 * only.
 *
 * D-29 adds what kind of run it was (`dry_run`: the file was read and
 * validated, nothing written) and whether it was undone (`undo_requested_at`
 * when the actor confirmed, then `undone_at`, `undone_by` and the counts of
 * records deleted and kept once the queued job finished).
 *
 * Retention: Filament leaves `prunable()` unimplemented; runs older than
 * RETENTION_DAYS are pruned by the daily `model:prune` schedule, and their
 * failed rows follow through the cascading foreign key, while the records a
 * run created keep living with their `import_id` cleared.
 *
 * @property ?Carbon $completed_at
 * @property bool $dry_run
 * @property ?Carbon $undo_requested_at
 * @property ?Carbon $undone_at
 * @property-read User $user
 * @property-read ?User $undoneBy
 */
final class Import extends FilamentImport
{
    public const RETENTION_DAYS = 90;

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
            'dry_run' => 'boolean',
            'undo_requested_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    /**
     * The user who undid the run (D-29).
     *
     * @return BelongsTo<User, $this>
     */
    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }

    /** A check-only run: validated and recorded, nothing written (D-29). */
    public function isDryRun(): bool
    {
        return $this->dry_run;
    }

    /** The actor confirmed the undo and the queued job has not finished yet (D-29). */
    public function isUndoing(): bool
    {
        return $this->undo_requested_at !== null && $this->undone_at === null;
    }

    public function isUndone(): bool
    {
        return $this->undone_at !== null;
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<=', now()->subDays(self::RETENTION_DAYS));
    }

    /** The user who launched the run. */
    public function isOwnedBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->getKey();
    }
}
