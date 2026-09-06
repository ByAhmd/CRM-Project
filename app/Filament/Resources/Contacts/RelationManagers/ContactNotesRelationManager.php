<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\RelationManagers;

use App\Filament\RelationManagers\BaseNotesRelationManager;

/**
 * The notes on a contact (decision A-10).
 */
final class ContactNotesRelationManager extends BaseNotesRelationManager
{
    protected static string $relationship = 'notes';

    protected static string $subjectForeignKey = 'contact_id';
}
