<?php

declare(strict_types=1);

namespace App\Filament\Resources\DealCloseReasons\Pages;

use App\Filament\Resources\DealCloseReasons\DealCloseReasonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListDealCloseReasons extends ListRecords
{
    protected static string $resource = DealCloseReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
