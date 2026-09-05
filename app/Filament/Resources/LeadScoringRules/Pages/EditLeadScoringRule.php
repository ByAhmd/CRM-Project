<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadScoringRules\Pages;

use App\Filament\Resources\LeadScoringRules\LeadScoringRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditLeadScoringRule extends EditRecord
{
    protected static string $resource = LeadScoringRuleResource::class;

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
