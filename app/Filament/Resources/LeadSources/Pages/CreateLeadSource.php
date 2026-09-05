<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadSources\Pages;

use App\Filament\Resources\LeadSources\LeadSourceResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateLeadSource extends CreateRecord
{
    protected static string $resource = LeadSourceResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
