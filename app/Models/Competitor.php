<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AuditsAsLookup;
use Database\Factories\CompetitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A competitor named on deals (decisions A-4, D-8).
 *
 * Competitor names are proper nouns, so this lookup carries one `name`
 * instead of the bilingual pair the other lookups use.
 *
 * @property bool $is_active
 */
#[Fillable(['name', 'website', 'notes', 'is_active'])]
final class Competitor extends Model
{
    use AuditsAsLookup;

    /** @use HasFactory<CompetitorFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected static array $recordEvents = ['created', 'updated', 'deleted', 'restored'];

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
        return ['name', 'website', 'notes', 'is_active'];
    }
}
