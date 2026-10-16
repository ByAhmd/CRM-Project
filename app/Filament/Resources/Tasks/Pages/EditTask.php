<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskChecklist;
use App\Services\Tasks\TaskService;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Editing goes through TaskService (A-10). The form's assignee field is
 * `assignee_ids` (D-28), filled here with every assignee, the first one
 * first, and handed back to the service, which turns a change of the first
 * into a reassignment and syncs the others; the status is read-only on the
 * form and changes only through the actions. The checklist (D-25) is filled
 * from the stored items, each with its id, and synced by the service. A
 * refused assignment becomes a danger notification (TaskForm::saving()).
 */
final class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        assert($record instanceof Task);

        // Every assignee, the first one first (D-28): the order is the meaning.
        $data[TaskForm::ASSIGNEES] = $record->assigneeIds();

        // The repeater always submits its state, so it must start from the
        // stored items or the save would wipe them (D-25).
        $data['checklist'] = TaskChecklist::formState($record);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Task);

        $actor = auth()->user();
        assert($actor instanceof User);

        return TaskForm::saving(static fn (): Task => app(TaskService::class)->update($record, $data, $actor));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
