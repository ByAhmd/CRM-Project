<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\OwnerSelect;
use App\Models\Account;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditAccount extends EditRecord
{
    protected static string $resource = AccountResource::class;

    /**
     * The custom field state the form submitted, held between the mutation of
     * the account's own columns and the hook that writes it against the saved
     * record (D-9).
     *
     * It is the *dehydrated* state, not the raw Livewire data: a date picker
     * keeps its own internal format in the raw data, while the dehydrated state
     * is the shape the definitions are validated and stored in.
     *
     * @var array<string, mixed>
     */
    private array $customFieldState = [];

    /** The owner the form submitted, or false when the actor may not reassign (D-4). */
    private int|false|null $submittedOwnerId = false;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * The stored custom field values alongside the account's own columns (D-9).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, ...CustomFieldActions::fillFormData($this->getRecord())];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $custom = $data[CustomFieldsSchema::STATE_PATH] ?? null;
        $this->customFieldState = is_array($custom) ? $custom : [];

        // Custom field values are not columns of the account: they are written
        // against the saved record by afterSave() (D-9).
        unset($data[CustomFieldsSchema::STATE_PATH]);

        $record = $this->getRecord();
        assert($record instanceof Account);
        $actor = auth()->user();

        // The lifecycle type follows the deals unless the actor may set it by
        // hand (D-6); the form never saves it for anyone else, and a crafted
        // payload is dropped here as well.
        if (! $actor instanceof User || ! $actor->can('setType', $record)) {
            unset($data['type'], $data['customer_since']);
        }

        // A change of owner is a reassignment (audit row + notification, D-4),
        // handed to RecordAssignmentService by afterSave().
        $this->submittedOwnerId = OwnerSelect::pull($data, $record);

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();
        assert($record instanceof Account);

        $ownerId = $this->submittedOwnerId;
        $this->submittedOwnerId = false;
        OwnerSelect::reassign($record, $ownerId);

        $this->persistCustomFields($record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
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
