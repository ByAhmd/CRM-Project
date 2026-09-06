<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Schemas\DealForm;
use App\Filament\Support\CustomFieldsSchema;
use App\Models\Deal;
use App\Models\User;
use App\Services\Deals\DealAmountCalculator;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The status is not part of the form: DealObserver derives Open from the
 * initial stage and the close columns start empty (D-8).
 */
final class CreateDeal extends CreateRecord
{
    protected static string $resource = DealResource::class;

    /**
     * The custom field state the form submitted, held between the mutation of
     * the deal's own columns and the hook that writes it against the saved
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
        $data['currency'] = DealResource::currency();
        $data['pipeline_id'] = $data['pipeline_id'] ?? DealForm::defaultPipelineId();
        $data['stage_id'] = $data['stage_id'] ?? DealForm::defaultStageId($data['pipeline_id']);

        $custom = $data[CustomFieldsSchema::STATE_PATH] ?? null;
        $this->customFieldState = is_array($custom) ? $custom : [];

        // Custom field values are not columns of the deal: they are written
        // against the saved record by afterCreate() (D-9).
        unset($data[CustomFieldsSchema::STATE_PATH]);

        return $data;
    }

    /**
     * The repeater saves the line items after the deal row, so the amount is
     * recomputed once they exist (D-8); the custom field values are written
     * against the saved deal at the same point (D-9).
     */
    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Deal) {
            return;
        }

        app(DealAmountCalculator::class)->recalculate($record);

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
