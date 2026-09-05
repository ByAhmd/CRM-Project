<?php

declare(strict_types=1);

namespace App\Filament\Resources\DealCloseReasons\Pages;

use App\Filament\Resources\DealCloseReasons\DealCloseReasonResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditDealCloseReason extends EditRecord
{
    protected static string $resource = DealCloseReasonResource::class;

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
