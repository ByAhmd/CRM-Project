<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use App\Models\Account;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Contact rules that span rows: one primary contact per account, and the
 * account a contact is linked to must be one the actor may read (D-4, D-6).
 */
final class ContactService
{
    /**
     * Whether the actor may link the contact to the account: no account, the
     * contact's current account (kept even when it is outside the actor's
     * scope or soft-deleted, so an unrelated edit still saves), or an account
     * the actor may view. Anything else would let a rep attach people to a
     * company they cannot see.
     */
    public function mayLinkAccount(User $actor, mixed $accountId, ?Contact $contact = null): bool
    {
        if ($accountId === null || $accountId === '') {
            return true;
        }

        if (! is_numeric($accountId)) {
            return false;
        }

        $accountId = (int) $accountId;

        $storedAccountId = $contact?->exists === true ? $contact->getOriginal('account_id') : null;

        if ($storedAccountId !== null && (int) $storedAccountId === $accountId) {
            return true;
        }

        $account = Account::query()->find($accountId);

        return $account instanceof Account && $actor->can('view', $account);
    }

    /**
     * Makes the contact the primary one of its account, demoting any other,
     * in one transaction. A contact without an account cannot be primary.
     */
    public function markPrimary(Contact $contact): void
    {
        if ($contact->account_id === null) {
            $contact->forceFill(['is_primary' => false])->save();

            return;
        }

        DB::transaction(function () use ($contact): void {
            Contact::query()
                ->where('account_id', $contact->account_id)
                ->whereKeyNot($contact->getKey())
                ->where('is_primary', true)
                ->get()
                ->each(fn (Contact $other) => $other->forceFill(['is_primary' => false])->save());

            if (! $contact->is_primary) {
                $contact->forceFill(['is_primary' => true])->save();
            }
        });
    }

    /** Applies the primary rule after a contact was saved with is_primary set. */
    public function enforcePrimaryRule(Contact $contact): void
    {
        if ($contact->is_primary) {
            $this->markPrimary($contact);
        }
    }
}
