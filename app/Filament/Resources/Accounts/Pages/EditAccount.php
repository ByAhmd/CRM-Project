<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\CustomFieldsSchema;
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

        return $data;
    }

    protected function afterSave(): void
    {
        $this->persistCustomFields($this->getRecord());
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
