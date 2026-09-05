<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Enums\AccountType;
use App\Enums\ActivityLogEvent;
use App\Models\Account;
use App\Models\Deal;
use App\Models\User;
use App\Services\Audit\AuditLogger;

/**
 * The account lifecycle (decision D-6): a prospect becomes a customer on its
 * first won deal. Customers, partners and other accounts are left as they
 * are; administrators change those by hand.
 */
final class AccountLifecycleService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return bool true when the account was promoted, false when it was not a prospect
     */
    public function promoteToCustomer(Account $account, User $actor, ?Deal $deal = null): bool
    {
        if ($account->type !== AccountType::Prospect) {
            return false;
        }

        $account->type = AccountType::Customer;
        $account->customer_since ??= today();
        $account->save();

        $this->audit->record(ActivityLogEvent::AccountBecameCustomer, $account, $actor, [
            'subject_label' => $account->name,
            'deal_id' => $deal?->getKey(),
            'deal_title' => $deal?->title,
        ]);

        return true;
    }
}
