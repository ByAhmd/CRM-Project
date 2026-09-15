<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Filament\RelationManagers\BaseNotesRelationManager;
use App\Models\User;
use App\Services\Notes\ReadableNoteScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The notes on an account (decision A-10), including those written on its
 * contacts and deals, which carry the account as well — but only the ones
 * whose contact or deal the actor may read (D-4): an account owner whose
 * deal scope is "own" does not read another rep's deal notes here. The rule
 * is ReadableNoteScope, shared with the account timeline.
 */
final class AccountNotesRelationManager extends BaseNotesRelationManager
{
    protected static string $relationship = 'notes';

    protected static string $subjectForeignKey = 'account_id';

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function scopeToReadableSubjects(Builder $query): Builder
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return app(ReadableNoteScope::class)->apply($query, $actor);
    }
}
