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
 * One task with its actions (decisions A-10, D-14, D-17, D-20). The assignee of a
 * handed-out task sees start, post update and complete here while edit,
 * cancel, delete and restore stay with those TaskPolicy lets edit it; anyone
 * who may view the task may comment on it, mentioning colleagues (D-20). The
 * thread — progress entries and comments — its authors and the colleagues
 * its comments mention are loaded with the record, and so are the checklist
 * items and who ticked them (D-25), which whoever may report on the task
 * ticks through the "Checklist" action.
 */
final class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        $record = parent::resolveRecord($key);

        return $record instanceof Task ? $record->loadMissing(['updates.author', 'updates.mentions.user', 'checklistItems.completer']) : $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            TaskActions::start(),
            TaskActions::postUpdate(),
            TaskActions::checklist(),
            TaskActions::comment(),
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
