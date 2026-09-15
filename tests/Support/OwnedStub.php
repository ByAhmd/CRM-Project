<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\OwnedRecord;
use App\Models\Concerns\HasOwner;
use Illuminate\Database\Eloquent\Model;

/**
 * A minimal owned record that isolates RecordVisibilityResolver and the
 * query-scoping trait from any real entity: no observers, workflow guards,
 * casts or policies of its own, only HasOwner and the `lead` permission
 * group's keys. Never persisted.
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
