<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Services\Projects\ProjectService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    /**
     * Creation goes through ProjectService so exactly one project stays the
     * default (D-30). The service names the field it refuses on; the form's
     * state path is prepended so the message lands beside that toggle.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ProjectService::class)->create($data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(Arr::prependKeysWith($exception->errors(), $this->form->getStatePath().'.'));
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
