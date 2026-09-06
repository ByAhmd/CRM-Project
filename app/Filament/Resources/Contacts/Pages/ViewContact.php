<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\EmailActions;
use App\Filament\Support\OwnershipActions;
use App\Models\Contact;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

final class ViewContact extends ViewRecord
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EmailActions::send(),
            EditAction::make(),
            OwnershipActions::assign(Contact::permissionGroup()),
            DeleteAction::make(),
        ];
    }

    /**
     * Every custom field entry of the page reads the record's values, so the
     * relation is loaded once with the record instead of once per entry (D-9).
     */
    protected function resolveRecord(int|string $key): Model
    {
        return CustomFieldActions::loadValues(parent::resolveRecord($key));
    }
}
