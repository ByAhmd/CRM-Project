<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Deal;
use App\Models\DealProduct;
use App\Services\Deals\DealAmountCalculator;

/**
 * Keeps a line item's total and its deal's amount current (decision D-8):
 * line_total = quantity × unit price × (1 − discount %), and the deal's
 * amount is recalculated after every line is saved or removed.
 */
final class DealProductObserver
{
    public function __construct(
        private readonly DealAmountCalculator $calculator,
    ) {}

    public function saving(DealProduct $line): void
    {
        $line->line_total = number_format(
            round((float) $line->quantity * (float) $line->unit_price * (1 - (float) $line->discount_percent / 100), 2),
            2,
            '.',
            '',
        );
    }

    public function saved(DealProduct $line): void
    {
        $this->recalculate($line);
    }

    public function deleted(DealProduct $line): void
    {
        $this->recalculate($line);
    }

    private function recalculate(DealProduct $line): void
    {
        /** @var Deal|null $deal */
        $deal = Deal::withTrashed()->find($line->deal_id);

        if ($deal !== null) {
            $this->calculator->recalculate($deal);
        }
    }
}
