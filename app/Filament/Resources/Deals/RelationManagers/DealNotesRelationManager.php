<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\RelationManagers;

use App\Filament\RelationManagers\BaseNotesRelationManager;

/**
 * The notes on a deal (decision A-10).
 */
final class DealNotesRelationManager extends BaseNotesRelationManager
{
    protected static string $relationship = 'notes';

    protected static string $subjectForeignKey = 'deal_id';
}
