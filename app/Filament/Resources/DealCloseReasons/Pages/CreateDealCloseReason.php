<?php

declare(strict_types=1);

namespace App\Filament\Resources\DealCloseReasons\Pages;

use App\Filament\Resources\DealCloseReasons\DealCloseReasonResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateDealCloseReason extends CreateRecord
{
    protected static string $resource = DealCloseReasonResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
