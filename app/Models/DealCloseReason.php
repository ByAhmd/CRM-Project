<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CloseReasonKind;
use App\Models\Concerns\AuditsAsLookup;
use App\Models\Concerns\HasLocalisedName;
use Database\Factories\DealCloseReasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A configurable reason a deal was won or lost (decisions D-8, A-4).
 *
 * @property CloseReasonKind $kind
 * @property bool $is_active
 * @property-read string $display_name
 */
#[Fillable(['kind', 'name_ar', 'name_en', 'is_active', 'sort'])]
final class DealCloseReason extends Model
{
    use AuditsAsLookup;

    /** @use HasFactory<DealCloseReasonFactory> */
    use HasFactory;

    use HasLocalisedName;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CloseReasonKind::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function auditedAttributes(): array
    {
        return ['kind', 'name_ar', 'name_en', 'is_active', 'sort'];
    }
}
