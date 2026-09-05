<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadStatuses\Pages;

use App\Exceptions\Settings\InvalidLeadStatusException;
use App\Filament\Resources\LeadStatuses\LeadStatusResource;
use App\Services\Settings\LeadStatusService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class CreateLeadStatus extends CreateRecord
{
    protected static string $resource = LeadStatusResource::class;

    /**
     * Creation goes through LeadStatusService so the single-default and
     * single-Converted invariants hold; a refusal aborts the whole save.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(LeadStatusService::class)->create($data);
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
