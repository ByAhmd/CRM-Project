<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityTypes\Pages;

use App\Filament\Resources\ActivityTypes\ActivityTypeResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateActivityType extends CreateRecord
{
    protected static string $resource = ActivityTypeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
