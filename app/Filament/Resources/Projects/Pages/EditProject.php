<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Services\Projects\ProjectService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // ProjectPolicy hides the action for the default project and for
            // one in use; the service refuses the same cases should the action
            // be called anyway, and the refusal is a toast, never an error page.
            DeleteAction::make()
                ->using(function (Project $record): bool {
                    try {
                        app(ProjectService::class)->delete($record);
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title((string) $exception->validator->errors()->first())
                            ->danger()
                            ->send();

                        throw new Halt;
                    }

                    return true;
                }),
        ];
    }

    /**
     * Updates go through ProjectService so exactly one project stays the
     * default and the default stays active (D-30). The service names the
     * field it refuses on; the form's state path is prepended so the message
     * lands beside that toggle.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Project);

        try {
            return app(ProjectService::class)->update($record, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(Arr::prependKeysWith($exception->errors(), $this->form->getStatePath().'.'));
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
