<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DealContactRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The pivot between a deal and one of its contacts, carrying the contact's
 * role in the deal (decision D-6).
 *
 * @property ?DealContactRole $role
 */
final class DealContact extends Pivot
{
    /**
     * Declared as a property rather than the #[Table] attribute: Larastan
     * instantiates models without their constructor, so only the property
     * lets checkModelProperties see the pivot columns.
     *
     * @var string
     */
    protected $table = 'deal_contacts';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => DealContactRole::class,
        ];
    }
}
