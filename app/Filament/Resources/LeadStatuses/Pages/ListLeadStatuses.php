<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadStatuses\Pages;

use App\Filament\Resources\LeadStatuses\LeadStatusResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListLeadStatuses extends ListRecords
{
    protected static string $resource = LeadStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
