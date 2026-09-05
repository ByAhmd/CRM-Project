<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\DealProductObserver;
use Database\Factories\DealProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A deal line item (decision D-8).
 *
 * `line_total` is computed on every save by DealProductObserver, which also
 * keeps the parent deal's amount in step. Line items are not audited on
 * their own: the resulting amount change is logged on the deal.
 *
 * @property string $quantity
 * @property string $unit_price
 * @property string $discount_percent
 * @property string $line_total
 */
#[Fillable(['deal_id', 'product_id', 'description', 'quantity', 'unit_price', 'discount_percent', 'sort'])]
#[ObservedBy(DealProductObserver::class)]
final class DealProduct extends Model
{
    /** @use HasFactory<DealProductFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
