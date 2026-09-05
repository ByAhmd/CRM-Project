<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * Contact rules that span rows: one primary contact per account.
 */
final class ContactService
{
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
