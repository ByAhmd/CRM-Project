<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\RelationManagers;

use App\Filament\RelationManagers\BaseAttachmentsRelationManager;

/**
 * Files attached to a contact (module row 13); everything lives in the base.
 */
final class ContactAttachmentsRelationManager extends BaseAttachmentsRelationManager
{
    protected static string $relationship = 'attachments';
}
