<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Services\Contacts\ContactService;
use Filament\Resources\Pages\CreateRecord;

final class CreateContact extends CreateRecord
{
    protected static string $resource = ContactResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['owner_id'] = $data['owner_id'] ?? auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();
        assert($record instanceof Contact);

        app(ContactService::class)->enforcePrimaryRule($record);
    }
}
