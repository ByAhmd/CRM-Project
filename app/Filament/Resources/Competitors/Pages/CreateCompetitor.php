<?php

declare(strict_types=1);

namespace App\Filament\Resources\Competitors\Pages;

use App\Filament\Resources\Competitors\CompetitorResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCompetitor extends CreateRecord
{
    protected static string $resource = CompetitorResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
