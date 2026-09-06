<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Filament\RelationManagers\BaseNotesRelationManager;

/**
 * The notes on a lead (decision A-10).
 */
final class LeadNotesRelationManager extends BaseNotesRelationManager
{
    protected static string $relationship = 'notes';

    protected static string $subjectForeignKey = 'lead_id';
}
