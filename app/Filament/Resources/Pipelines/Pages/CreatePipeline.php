<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pipelines\Pages;

use App\Exceptions\Settings\InvalidPipelineException;
use App\Filament\Resources\Pipelines\PipelineResource;
use App\Services\Settings\PipelineService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class CreatePipeline extends CreateRecord
{
    protected static string $resource = PipelineResource::class;

    /**
     * Creation goes through PipelineService so the single default pipeline is
     * kept and the new pipeline starts with a valid stage set; a refusal
     * aborts the whole save.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PipelineService::class)->create($data);
        } catch (InvalidPipelineException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
