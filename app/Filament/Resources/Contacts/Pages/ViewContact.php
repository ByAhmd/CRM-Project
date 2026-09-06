<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Support\EmailActions;
use App\Filament\Support\OwnershipActions;
use App\Models\Contact;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

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
}
