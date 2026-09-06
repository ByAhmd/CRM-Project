<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Creating a task goes through TaskService, never Model::create, so the
 * assignee defaults to the actor, the recurrence settings are normalised and
 * the creation is audited (A-10). The linked-record ids were re-checked by
 * the pickers against the actor's reach.
 */
final class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        return app(TaskService::class)->create($data, $actor);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
