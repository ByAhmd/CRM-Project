<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AuditsAsLookup;
use App\Models\Concerns\HasLocalisedName;
use Database\Factories\IndustryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An industry sector an account or lead operates in (decision A-4).
 *
 * @property bool $is_active
 * @property-read string $display_name
 */
#[Fillable(['name_ar', 'name_en', 'is_active', 'sort'])]
final class Industry extends Model
{
    use AuditsAsLookup;

    /** @use HasFactory<IndustryFactory> */
    use HasFactory;

    use HasLocalisedName;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function auditedAttributes(): array
    {
        return ['name_ar', 'name_en', 'is_active', 'sort'];
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
