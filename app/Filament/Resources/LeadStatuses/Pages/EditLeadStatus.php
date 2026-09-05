<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadStatuses\Pages;

use App\Exceptions\Settings\InvalidLeadStatusException;
use App\Filament\Resources\LeadStatuses\LeadStatusResource;
use App\Models\LeadStatus;
use App\Services\Settings\LeadStatusService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class EditLeadStatus extends EditRecord
{
    protected static string $resource = LeadStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (LeadStatus $record): bool {
                    try {
                        app(LeadStatusService::class)->delete($record);
                    } catch (InvalidLeadStatusException $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();

                        throw new Halt;
                    }

                    return true;
                }),
        ];
    }

    /**
     * Updates go through LeadStatusService so the single-default and
     * single-Converted invariants hold; a refusal aborts the whole save.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof LeadStatus);

        try {
            return app(LeadStatusService::class)->update($record, $data);
        } catch (InvalidLeadStatusException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
