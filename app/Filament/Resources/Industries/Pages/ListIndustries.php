<?php

declare(strict_types=1);

namespace App\Filament\Resources\Industries\Pages;

use App\Filament\Resources\Industries\IndustryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListIndustries extends ListRecords
{
    protected static string $resource = IndustryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
