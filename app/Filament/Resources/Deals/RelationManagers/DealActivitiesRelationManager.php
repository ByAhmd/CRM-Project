<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\RelationManagers;

use App\Filament\RelationManagers\BaseActivitiesRelationManager;

/**
 * The activity timeline of a deal (decision A-10). Everything lives in
 * the base manager; this class only names the relationship.
 */
final class DealActivitiesRelationManager extends BaseActivitiesRelationManager
{
    protected static string $relationship = 'activities';
}
