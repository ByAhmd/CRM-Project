<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pipelines\Pages;

use App\Exceptions\Settings\InvalidPipelineException;
use App\Filament\Resources\Pipelines\PipelineResource;
use App\Models\Pipeline;
use App\Services\Settings\PipelineService;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class EditPipeline extends EditRecord
{
    protected static string $resource = PipelineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (Pipeline $record): bool {
                    try {
                        app(PipelineService::class)->delete($record);
                    } catch (InvalidPipelineException $exception) {
                        $this->refuse($exception);
                    }

                    return true;
                }),
            RestoreAction::make(),
        ];
    }

    /**
     * Updates go through PipelineService so the single default pipeline is
     * kept, the default can never be deactivated and the stage set stays
     * valid; a refusal aborts the whole save.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Pipeline);

        try {
            return app(PipelineService::class)->update($record, $data);
        } catch (InvalidPipelineException $exception) {
            $this->refuse($exception);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    private function refuse(InvalidPipelineException $exception): never
    {
        Notification::make()
            ->title($exception->getMessage())
            ->danger()
            ->send();

        throw new Halt;
    }
}
