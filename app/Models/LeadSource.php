<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AuditsAsLookup;
use App\Models\Concerns\HasLocalisedName;
use Database\Factories\LeadSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a lead came from (decisions D-7, A-4).
 *
 * @property bool $is_active
 * @property-read string $display_name
 */
#[Fillable(['name_ar', 'name_en', 'is_active', 'sort'])]
final class LeadSource extends Model
{
    use AuditsAsLookup;

    /** @use HasFactory<LeadSourceFactory> */
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
}
