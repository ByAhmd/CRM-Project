<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One organisation setting row (decision D-2). Read and written only through
 * SettingsRepository, which owns the cache and the audit event.
 *
 * @property mixed $value
 */
#[Fillable(['key', 'value', 'group'])]
final class Setting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
