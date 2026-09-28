<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\RelationManagers;

use App\Filament\RelationManagers\BaseAttachmentsRelationManager;

/**
 * Files attached to a task (module row 13, D-15): an employee hands in the
 * file a task asked for here. Everything lives in the base; AttachmentPolicy
 * follows TaskPolicy's `view`, so the panel and its downloads reach exactly
 * the tasks the reader may open.
 */
final class TaskAttachmentsRelationManager extends BaseAttachmentsRelationManager
{
    protected static string $relationship = 'attachments';
}
