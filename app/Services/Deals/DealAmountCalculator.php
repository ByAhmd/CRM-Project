<?php

declare(strict_types=1);

namespace App\Services\Deals;

use App\Models\Deal;

/**
 * The deal amount rule (decision D-8): when a deal has line items its amount
 * is their sum; a deal without lines keeps whatever amount was entered by
 * hand. The amount is not a workflow-guarded column, so the write goes
 * through a normal save and the change is audited on the deal by LogsActivity.
 */
final class DealAmountCalculator
{
    public function hasLines(Deal $deal): bool
    {
        return $deal->products()->exists();
    }

    public function recalculate(Deal $deal): void
    {
        if (! $this->hasLines($deal)) {
            return;
        }

        $sum = $deal->products()->sum('line_total');

        $deal->amount = number_format(round((float) $sum, 2), 2, '.', '');

        if ($deal->isDirty('amount')) {
            $deal->save();
        }
    }
}
