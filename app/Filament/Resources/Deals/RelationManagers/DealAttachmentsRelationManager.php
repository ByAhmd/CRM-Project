<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\RelationManagers;

use App\Filament\RelationManagers\BaseAttachmentsRelationManager;

/**
 * Files attached to a deal (module row 13); everything lives in the base.
 */
final class DealAttachmentsRelationManager extends BaseAttachmentsRelationManager
{
    protected static string $relationship = 'attachments';
}
