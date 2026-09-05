<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\OwnedRecord;
use App\Models\Concerns\HasOwner;
use Illuminate\Database\Eloquent\Model;

/**
 * A minimal owned record for exercising RecordVisibilityResolver before the
 * real owned entities exist. Never persisted.
 */
final class OwnedStub extends Model implements OwnedRecord
{
    use HasOwner;

    protected $table = 'owned_stubs';

    protected $guarded = [];

    public static function permissionGroup(): string
    {
        return 'lead';
    }
}
