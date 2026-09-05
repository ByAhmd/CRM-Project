<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadSources\Pages;

use App\Filament\Resources\LeadSources\LeadSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListLeadSources extends ListRecords
{
    protected static string $resource = LeadSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
