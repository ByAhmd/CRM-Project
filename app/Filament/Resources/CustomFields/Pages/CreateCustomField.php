<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomFields\Pages;

use App\Exceptions\CustomFields\InvalidCustomFieldException;
use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Services\CustomFields\CustomFieldService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class CreateCustomField extends CreateRecord
{
    protected static string $resource = CustomFieldResource::class;

    /**
     * The two bags are always sent, even when their sections are hidden: a
     * type without choices or constraints must clear them, not inherit them.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['options'] = $data['options'] ?? [];
        $data['validation'] = $data['validation'] ?? [];

        return $data;
    }

    /**
     * Creation goes through CustomFieldService so the key, option and
     * constraint invariants hold; a refusal aborts the whole save.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CustomFieldService::class)->create($data);
        } catch (InvalidCustomFieldException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
