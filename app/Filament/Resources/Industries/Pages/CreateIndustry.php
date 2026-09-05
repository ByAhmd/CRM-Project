<?php

declare(strict_types=1);

namespace App\Filament\Resources\Industries\Pages;

use App\Filament\Resources\Industries\IndustryResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateIndustry extends CreateRecord
{
    protected static string $resource = IndustryResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
