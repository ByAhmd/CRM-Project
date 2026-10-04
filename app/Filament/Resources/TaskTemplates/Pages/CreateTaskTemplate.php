<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskTemplates\Pages;

use App\Filament\Resources\TaskTemplates\Schemas\TaskTemplateForm;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use App\Models\User;
use App\Services\Tasks\RoutineTaskService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Creating a routine goes through RoutineTaskService (D-22): the schedule is
 * normalised, the assignees are checked against the actor's assignment reach,
 * the actor becomes the routine's assigner and its first run day is set. A
 * refusal shows under the field it concerns.
 */
final class CreateTaskTemplate extends CreateRecord
{
    protected static string $resource = TaskTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        try {
            return app(RoutineTaskService::class)->save($data, $actor);
        } catch (ValidationException $exception) {
            throw TaskTemplateForm::formErrors($exception);
        }
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('task_templates.notifications.created');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
