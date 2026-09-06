<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\RelationManagers;

use App\Filament\RelationManagers\BaseTasksRelationManager;

/**
 * The tasks on a deal (decision A-10). Everything lives in the base
 * manager; this class only names the relationship and the column that
 * points at the deal.
 */
final class DealTasksRelationManager extends BaseTasksRelationManager
{
    protected static string $relationship = 'tasks';

    protected static string $subjectForeignKey = 'deal_id';
}
