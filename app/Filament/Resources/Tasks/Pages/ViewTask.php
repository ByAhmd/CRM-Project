<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\OwnershipActions;
use App\Filament\Support\TaskActions;
use App\Models\Task;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * One task with its actions (decisions A-10, D-14). The assignee of a
 * handed-out task sees start, post update and complete here while edit,
 * cancel, delete and restore stay with those TaskPolicy lets edit it; the
 * progress log and its authors are loaded with the record.
 */
final class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        $record = parent::resolveRecord($key);

        return $record instanceof Task ? $record->loadMissing(['updates.author']) : $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            TaskActions::start(),
            TaskActions::postUpdate(),
            TaskActions::complete(),
            TaskActions::cancel(),
            TaskActions::reopen(),
            OwnershipActions::assign(Task::permissionGroup()),
            EditAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
