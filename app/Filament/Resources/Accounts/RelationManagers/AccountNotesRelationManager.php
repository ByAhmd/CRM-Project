<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Filament\RelationManagers\BaseNotesRelationManager;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * The notes on an account (decision A-10), including those written on its
 * contacts and deals, which carry the account as well — but only the ones
 * whose contact or deal the actor may read (D-4): an account owner whose
 * deal scope is "own" does not read another rep's deal notes here.
 */
final class AccountNotesRelationManager extends BaseNotesRelationManager
{
    protected static string $relationship = 'notes';

    protected static string $subjectForeignKey = 'account_id';

    /**
     * A note written on the account itself has neither a contact nor a deal;
     * a contact or deal note passes only when its subject is in the actor's
     * scope, taken from the resources' scoped queries (trashed subjects
     * included, as Note::subjectRecord() resolves them). The service never
     * writes both keys, so the two branches cover every row.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function scopeToReadableSubjects(Builder $query): Builder
    {
        return $query->where(fn (Builder $scope): Builder => $scope
            ->where(fn (Builder $onAccount): Builder => $onAccount->whereNull('notes.contact_id')->whereNull('notes.deal_id'))
            ->orWhereIn('notes.contact_id', ContactResource::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])->select('contacts.id'))
            ->orWhereIn('notes.deal_id', DealResource::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])->select('deals.id')));
    }
}
