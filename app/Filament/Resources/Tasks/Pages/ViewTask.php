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

final class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
