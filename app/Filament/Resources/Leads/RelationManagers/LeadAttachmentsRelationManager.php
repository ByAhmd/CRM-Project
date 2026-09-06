<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Filament\RelationManagers\BaseAttachmentsRelationManager;

/**
 * Files attached to a lead (module row 13); everything lives in the base.
 */
final class LeadAttachmentsRelationManager extends BaseAttachmentsRelationManager
{
    protected static string $relationship = 'attachments';
}
