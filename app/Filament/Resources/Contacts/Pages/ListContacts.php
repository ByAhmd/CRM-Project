<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Concerns\HasSavedViews;
use App\Filament\Imports\ContactImporter;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Support\ImportExportActions;
use App\Filament\Support\SavedViewActions;
use App\Models\Contact;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListContacts extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...SavedViewActions::for($this),
            ImportExportActions::import(ContactImporter::class, Contact::class),
            CreateAction::make(),
        ];
    }
}
