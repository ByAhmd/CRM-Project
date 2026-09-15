<?php

declare(strict_types=1);

namespace App\Services\Notes;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Narrows a notes query to the notes whose subject the viewer may read (D-4).
 *
 * NoteService copies the account onto the notes written on its contacts and
 * deals, so every "notes of this account" query also finds notes whose real
 * subject carries its own record scope: an account owner whose deal scope is
 * "own" must not read another rep's deal notes through the account. A note
 * passes when it was written on the account itself (neither a contact nor a
 * deal), or when its contact or deal is in the viewer's visible scope —
 * trashed subjects included, as Note::subjectRecord() resolves them. The
 * service never writes both keys, so the branches cover every row.
 *
 * The one rule behind the account notes relation manager and the account
 * timeline, so the two surfaces cannot drift apart.
 */
final readonly class ReadableNoteScope
{
    public function __construct(
        private RecordVisibilityResolver $resolver,
    ) {}

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $notes  a query over the notes table
     * @return Builder<TModel>
     */
    public function apply(Builder $notes, User $viewer): Builder
    {
        $table = $notes->getModel()->getTable();

        return $notes->where(fn (Builder $scope): Builder => $scope
            ->where(static fn (Builder $onAccount): Builder => $onAccount
                ->whereNull($table.'.contact_id')
                ->whereNull($table.'.deal_id'))
            ->orWhereIn($table.'.contact_id', $this->resolver->visible($viewer, Contact::query()->withTrashed())->select('contacts.id'))
            ->orWhereIn($table.'.deal_id', $this->resolver->visible($viewer, Deal::query()->withTrashed())->select('deals.id')));
    }
}
