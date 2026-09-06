<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Filament\RelationManagers\BaseAttachmentsRelationManager;

/**
 * Files attached to an account (module row 13); everything lives in the base.
 */
final class AccountAttachmentsRelationManager extends BaseAttachmentsRelationManager
{
    protected static string $relationship = 'attachments';
}
