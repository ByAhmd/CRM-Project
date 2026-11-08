<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Support\CustomFieldsSchema;
use App\Models\Contact;
use App\Models\User;
use App\Services\Contacts\ContactService;
use App\Services\Projects\CurrentProject;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateContact extends CreateRecord
{
    protected static string $resource = ContactResource::class;

    /**
     * The custom field state the form submitted, held between the mutation of
     * the contact's own columns and the hook that writes it against the saved
     * record (D-9).
     *
     * It is the *dehydrated* state, not the raw Livewire data: a date picker
     * keeps its own internal format in the raw data, while the dehydrated state
     * is the shape the definitions are validated and stored in.
     *
     * @var array<string, mixed>
     */
    private array $customFieldState = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        $data['created_by'] = $actor->getKey();
        $data['owner_id'] = $data['owner_id'] ?? $actor->getKey();

        // The contact is born in the project the user is working in (D-30):
        // the form shows it as a badge and never offers a choice, so the page
        // stamps it instead of reading it from the payload.
        $data['project_id'] = app(CurrentProject::class)->for($actor)->getKey();

        $custom = $data[CustomFieldsSchema::STATE_PATH] ?? null;
        $this->customFieldState = is_array($custom) ? $custom : [];

        // Custom field values are not columns of the contact: they are written
        // against the saved record by afterCreate() (D-9).
        unset($data[CustomFieldsSchema::STATE_PATH]);

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();
        assert($record instanceof Contact);

        app(ContactService::class)->enforcePrimaryRule($record);

        $this->persistCustomFields($record);
    }

    /** The submitted values, once the record has an id (D-9). */
    private function persistCustomFields(Model $record): void
    {
        $state = $this->customFieldState;
        $this->customFieldState = [];
        $actor = auth()->user();

        if ($state === [] || ! $actor instanceof User) {
            return;
        }

        CustomFieldsSchema::persist($record, [CustomFieldsSchema::STATE_PATH => $state], $actor);
    }
}
