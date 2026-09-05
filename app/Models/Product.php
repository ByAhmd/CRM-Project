<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AuditsAsLookup;
use App\Models\Concerns\HasLocalisedName;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A catalogue product (decision D-8).
 *
 * @property string|null $code
 * @property string $unit_price
 * @property bool $is_active
 * @property-read string $display_name
 */
#[Fillable(['code', 'name_ar', 'name_en', 'unit_price', 'is_active'])]
final class Product extends Model
{
    use AuditsAsLookup;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasLocalisedName;
    use SoftDeletes;

    /** @var list<string> */
    protected static array $recordEvents = ['created', 'updated', 'deleted', 'restored'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function auditedAttributes(): array
    {
        return ['code', 'name_ar', 'name_en', 'unit_price', 'is_active'];
    }

    /**
     * @return HasMany<DealProduct, $this>
     */
    public function dealProducts(): HasMany
    {
        return $this->hasMany(DealProduct::class);
    }

    /**
     * Codes are stored trimmed and upper-cased (D-8 suggests uppercase; the
     * normalisation is recorded in docs/DATABASE_DESIGN.md and disclosed in
     * the form helper text), and a blank code is stored as NULL so the
     * unique index never collides on empty strings.
     *
     * @return Attribute<never, string|null>
     */
    protected function code(): Attribute
    {
        return Attribute::make(set: static function (?string $value): ?string {
            $code = mb_strtoupper(trim((string) $value));

            return $code !== '' ? $code : null;
        });
    }
}
