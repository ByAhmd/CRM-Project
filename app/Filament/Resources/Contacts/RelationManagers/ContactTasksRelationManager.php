<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\RelationManagers;

use App\Filament\RelationManagers\BaseTasksRelationManager;

/**
 * The tasks on a contact (decision A-10). Everything lives in the base
 * manager; this class only names the relationship and the column that
 * points at the contact.
 */
final class ContactTasksRelationManager extends BaseTasksRelationManager
{
    protected static string $relationship = 'tasks';

    protected static string $subjectForeignKey = 'contact_id';
}
