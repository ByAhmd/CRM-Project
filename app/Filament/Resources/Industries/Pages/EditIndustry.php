<?php

declare(strict_types=1);

namespace App\Filament\Resources\Industries\Pages;

use App\Filament\Resources\Industries\IndustryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditIndustry extends EditRecord
{
    protected static string $resource = IndustryResource::class;

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
