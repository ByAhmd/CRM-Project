<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskTemplates\Pages;

use App\Filament\Resources\TaskTemplates\Schemas\TaskTemplateForm;
use App\Filament\Resources\TaskTemplates\TaskTemplateActions;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\Tasks\RoutineTaskService;
use App\Services\Tasks\TaskChecklist;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Editing a routine goes through RoutineTaskService (D-22): saving makes the
 * actor its assigner, and a changed schedule recomputes its next run day from
 * today. The assignees are filled from the pivot and the checklist from its
 * rows (D-25); pausing, resuming and
 * deleting are header actions.
 */
final class EditTaskTemplate extends EditRecord
{
    protected static string $resource = TaskTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TaskTemplateActions::pause(),
            TaskTemplateActions::resume(),
            TaskTemplateActions::delete(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        assert($record instanceof TaskTemplate);

        $data['assignees'] = $record->assignees()->pluck('users.id')->map(static fn (mixed $id): int => (int) $id)->all();
        $data['due_time'] = substr((string) ($data['due_time'] ?? RoutineTaskService::DEFAULT_DUE_TIME), 0, 5);
        // The repeater submits its whole state, so it starts from the stored lines (D-25).
        $data['checklist'] = TaskChecklist::templateBodies($record);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof TaskTemplate);

        $actor = auth()->user();
        assert($actor instanceof User);

        try {
            return app(RoutineTaskService::class)->save($data, $actor, $record);
        } catch (ValidationException $exception) {
            throw TaskTemplateForm::formErrors($exception);
        }
    }

    protected function getSavedNotificationTitle(): string
    {
        return __('task_templates.notifications.saved');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
