<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The pivot between a deal and a competitor named on it (decision D-8);
 * `is_winner` marks who a lost deal went to.
 *
 * @property bool $is_winner
 */
final class DealCompetitor extends Pivot
{
    /**
     * Declared as a property rather than the #[Table] attribute: Larastan
     * instantiates models without their constructor, so only the property
     * lets checkModelProperties see the pivot columns.
     *
     * @var string
     */
    protected $table = 'deal_competitors';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_winner' => 'boolean',
        ];
    }
}
