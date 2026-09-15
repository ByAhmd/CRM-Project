<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Filament\Support\CustomFieldsSchema;
use App\Models\LeadStatus;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;

    /**
     * The custom field state the form submitted, held between the mutation of
     * the lead's own columns and the hook that writes it against the saved
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
        $data['created_by'] = auth()->id();
        $data['owner_id'] = $data['owner_id'] ?? auth()->id();
        $data['lead_status_id'] = $data['lead_status_id'] ?? LeadStatus::query()->where('is_default', true)->value('id');

        $this->refuseReservedInitialStatus($data['lead_status_id']);

        $custom = $data[CustomFieldsSchema::STATE_PATH] ?? null;
        $this->customFieldState = is_array($custom) ? $custom : [];

        // Custom field values are not columns of the lead: they are written
        // against the saved record by afterCreate() (D-9).
        unset($data[CustomFieldsSchema::STATE_PATH]);

        return $data;
    }

    /** The custom field values, once the lead has an id (D-9). */
    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record instanceof Model) {
            $this->persistCustomFields($record);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * A lead is never created in a Qualified or Converted status (D-7): the
     * qualification note and the conversion are the workflow's, so a crafted
     * status id is refused here even though the Select does not offer it.
     */
    private function refuseReservedInitialStatus(mixed $statusId): void
    {
        $kind = $statusId === null ? null : LeadStatus::query()->whereKey((int) $statusId)->first()?->kind;

        if (! in_array($kind, LeadForm::RESERVED_INITIAL_KINDS, true)) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('leads.validation.initial_status_reserved'))
            ->send();

        $this->halt();
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
