<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Actions\Imports\Models\Import as FilamentImport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * An import run (module 18, decision D-1).
 *
 * Filament's ImportAction writes the row through its own model class; this
 * subclass reads the same table and exists so the application can register
 * ImportPolicy against it (Laravel maps policies by model class) and point
 * ImportResource at it. It adds nothing to the row shape, so Filament's
 * #[Fillable]-less `$guarded = []` stays as inherited.
 *
 * Retention: Filament leaves `prunable()` unimplemented; runs older than
 * RETENTION_DAYS are pruned by the daily `model:prune` schedule, and their
 * failed rows follow through the cascading foreign key.
 *
 * @property ?Carbon $completed_at
 * @property-read User $user
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
        ];
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
