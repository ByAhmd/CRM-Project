<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Editing goes through TaskService (A-10). The form's assignee field is
 * OwnerSelect's `owner_id`, filled from `assignee_id` here and mapped back
 * by the service; the status is read-only on the form and changes only
 * through the actions.
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
        $data['owner_id'] = $data['assignee_id'] ?? null;

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

        return app(TaskService::class)->update($record, $data, $actor);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
