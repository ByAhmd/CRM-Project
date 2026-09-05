<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityTypes\Pages;

use App\Filament\Resources\ActivityTypes\ActivityTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditActivityType extends EditRecord
{
    protected static string $resource = ActivityTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
