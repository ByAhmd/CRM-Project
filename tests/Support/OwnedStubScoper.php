<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Filament\Concerns\ScopesQueriesToVisibleRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Exposes the resource query-scoping trait for tests.
 */
final class OwnedStubScoper
{
    use ScopesQueriesToVisibleRecords;

    /**
     * @return Builder<OwnedStub>
     */
    public static function visibleStubs(): Builder
    {
        return self::constrainToVisible(OwnedStub::query());
    }
}
