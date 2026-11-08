<?php

declare(strict_types=1);

namespace App\Observers;

use App\Exceptions\Projects\CrossProjectLinkException;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealContact;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Model;

/**
 * Integrity guard of the project partition (decision D-30): a contact and
 * its account, a deal and its account, its primary contact and the contacts
 * holding a role on it, an account and its parent always belong to the same
 * project — and a record never changes project once it exists.
 *
 * The pickers offer only records of the same project and the forms carry a
 * validation rule as the first line of defence (and never offer the project
 * at all); this observer is the last one, on `saving`, so a crafted request,
 * an importer or a service can never tie two projects together or move a
 * record between them, whatever path it takes. The lookups are set-based
 * (`value('project_id')` by key, trashed rows included — a link to a
 * since-deleted record keeps validating on an unrelated edit, as the pickers
 * keep it) and run only when a link or the project changed, so an ordinary
 * save costs nothing.
 *
 * A record that arrives with a link but no project yet — a contact or a
 * deal created from an account's own page, where the relation manager sets
 * the account before anything else — takes the linked record's project, so
 * every creation path ends in the parent record's project, as D-30 asks; the
 * `creating` guard of Models\Concerns\BelongsToProject then finds the column
 * set and leaves it alone. A linked id that matches no row is left to the
 * foreign key, which refuses it.
 *
 * Observed by the four partitioned models (the lead has no link to guard,
 * only its project to keep) and by the deal–contact pivot, which a
 * BelongsToMany attach saves through its own model, so the roles tab has the
 * same last line of defence as the columns.
 */
final class ProjectConsistencyObserver
{
    public function saving(Account|Contact|Deal|DealContact|Lead $record): void
    {
        if ($record instanceof DealContact) {
            $this->guardDealContact($record);

            return;
        }

        $this->refuseMove($record);

        match (true) {
            $record instanceof Contact => $this->guardContact($record),
            $record instanceof Deal => $this->guardDeal($record),
            $record instanceof Account => $this->guardAccount($record),
            default => null,
        };
    }

    /**
     * `project_id` is set when the record is created and never edited
     * afterwards (D-30): the edit pages drop it, the importers stamp new rows
     * only, and anything else that tries is refused here.
     */
    private function refuseMove(Account|Contact|Deal|Lead $record): void
    {
        if ($record->exists && $record->isDirty('project_id')) {
            throw CrossProjectLinkException::moved($record);
        }
    }

    private function guardContact(Contact $contact): void
    {
        if (! $contact->isDirty(['account_id', 'project_id'])) {
            return;
        }

        $this->reconcileWithAccount($contact, $contact->account_id);
    }

    private function guardDeal(Deal $deal): void
    {
        if (! $deal->isDirty(['account_id', 'contact_id', 'project_id'])) {
            return;
        }

        $this->reconcileWithAccount($deal, $deal->account_id);

        if ($deal->contact_id === null) {
            return;
        }

        $contactId = (int) $deal->contact_id;
        $contactProject = self::projectOf(Contact::class, $contactId);

        if ($contactProject !== null) {
            $this->reconcile($deal, $contactProject, static fn (): CrossProjectLinkException => CrossProjectLinkException::contact($deal, $contactId));
        }
    }

    private function guardAccount(Account $account): void
    {
        if (! $account->isDirty(['parent_account_id', 'project_id']) || $account->parent_account_id === null) {
            return;
        }

        $parentId = (int) $account->parent_account_id;
        $parentProject = self::projectOf(Account::class, $parentId);

        if ($parentProject !== null) {
            $this->reconcile($account, $parentProject, static fn (): CrossProjectLinkException => CrossProjectLinkException::parent($account, $parentId));
        }
    }

    /**
     * A role on a deal (the `deal_contacts` pivot, D-6) ties a contact to the
     * deal: both must sit in one project. A pivot has no project of its own,
     * so there is nothing to inherit — only the two sides to compare.
     */
    private function guardDealContact(DealContact $pivot): void
    {
        if (! $pivot->isDirty(['deal_id', 'contact_id'])) {
            return;
        }

        $contactId = (int) $pivot->contact_id;
        $dealProject = self::projectOf(Deal::class, (int) $pivot->deal_id);
        $contactProject = self::projectOf(Contact::class, $contactId);

        if ($dealProject !== null && $contactProject !== null && $dealProject !== $contactProject) {
            throw CrossProjectLinkException::contact($pivot, $contactId);
        }
    }

    private function reconcileWithAccount(Contact|Deal $record, mixed $accountId): void
    {
        if ($accountId === null) {
            return;
        }

        $accountId = (int) $accountId;
        $accountProject = self::projectOf(Account::class, $accountId);

        if ($accountProject !== null) {
            $this->reconcile($record, $accountProject, static fn (): CrossProjectLinkException => CrossProjectLinkException::account($record, $accountId));
        }
    }

    /**
     * The record takes the linked record's project when it has none yet, and
     * is refused when it has another.
     *
     * @param  callable(): CrossProjectLinkException  $refusal
     */
    private function reconcile(Model $record, int $linkedProject, callable $refusal): void
    {
        $own = $record->getAttribute('project_id');

        if ($own === null) {
            $record->setAttribute('project_id', $linkedProject);

            return;
        }

        if ((int) $own !== $linkedProject) {
            throw $refusal();
        }
    }

    /**
     * The project of one account, contact or deal by key, trashed rows
     * included; null when no such row exists.
     *
     * @param  class-string<Account|Contact|Deal>  $model
     */
    private static function projectOf(string $model, int $id): ?int
    {
        $project = $model::withTrashed()->whereKey($id)->toBase()->value('project_id');

        return $project === null ? null : (int) $project;
    }
}
