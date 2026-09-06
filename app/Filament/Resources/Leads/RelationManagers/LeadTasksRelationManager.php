<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Filament\RelationManagers\BaseTasksRelationManager;

/**
 * The tasks on a lead (decision A-10). Everything lives in the base
 * manager; this class only names the relationship and the column that
 * points at the lead.
 */
final class LeadTasksRelationManager extends BaseTasksRelationManager
{
    protected static string $relationship = 'tasks';

    protected static string $subjectForeignKey = 'lead_id';
}
