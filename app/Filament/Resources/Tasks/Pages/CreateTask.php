<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\TaskActions;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Creating a task goes through TaskService, never Model::create, so the
 * assignee defaults to the actor, the recurrence settings are normalised and
 * the creation is audited (A-10). The linked-record ids were re-checked by
 * the pickers against the actor's reach. A holder of `task.assign` sends
 * every assignee as `assignee_ids` (D-28), the first one first; the service
 * checks each against the actor's reach, and a refusal becomes a danger
 * notification (TaskForm::saving()).
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

        return TaskForm::saving(static fn (): Model => app(TaskService::class)->create($data, $actor));
    }

    /**
     * Quick add's «خيارات أكثر» (D-27) opens this page with a one-time token
     * in the address; the values typed so far wait in the reader's session
     * and are filled over the form's defaults only as far as
     * TaskActions::takePrefill() validates them. The save goes through
     * TaskService like any other.
     *
     * The values are written whole and then hydrated, key by key: the
     * assignees (D-28) are a list, which fillPartially() would flatten into
     * `assignee_ids.0`, `assignee_ids.1` … and so never match the field.
     */
    protected function afterFill(): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        $prefill = TaskActions::takePrefill(request()->query(TaskActions::PREFILL_PARAMETER), $actor);

        if ($prefill === []) {
            return;
        }

        $statePath = $this->form->getStatePath();

        $this->form->partialRawState($prefill);
        $this->form->hydrateStatePartially(array_map(
            static fn (string $key): string => filled($statePath) ? "{$statePath}.{$key}" : $key,
            array_keys($prefill),
        ));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
