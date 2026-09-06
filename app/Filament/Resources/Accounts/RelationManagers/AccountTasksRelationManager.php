<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Filament\RelationManagers\BaseTasksRelationManager;

/**
 * The tasks on an account (decision A-10). Everything lives in the base
 * manager; this class only names the relationship and the column that
 * points at the account.
 */
final class AccountTasksRelationManager extends BaseTasksRelationManager
{
    protected static string $relationship = 'tasks';

    protected static string $subjectForeignKey = 'account_id';
}
