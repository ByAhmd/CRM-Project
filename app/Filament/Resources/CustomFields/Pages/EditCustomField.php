<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomFields\Pages;

use App\Exceptions\CustomFields\InvalidCustomFieldException;
use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Models\CustomField;
use App\Services\CustomFields\CustomFieldService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class EditCustomField extends EditRecord
{
    protected static string $resource = CustomFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->disabled(fn (CustomField $record): bool => ! app(CustomFieldService::class)->isDeletable($record))
                ->tooltip(fn (CustomField $record): ?string => app(CustomFieldService::class)->isDeletable($record)
                    ? null
                    : __('custom_fields.helpers.delete_blocked'))
                ->using(function (CustomField $record): bool {
                    try {
                        app(CustomFieldService::class)->delete($record);
                    } catch (InvalidCustomFieldException $exception) {
                        Notification::make()
                            ->title($exception->getMessage())
                            ->danger()
                            ->send();

                        throw new Halt;
                    }

                    return true;
                }),
        ];
    }

    /**
     * The two bags are always sent, even when their sections are hidden: a
     * type without choices or constraints must clear them, not keep them.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['options'] = $data['options'] ?? [];
        $data['validation'] = $data['validation'] ?? [];

        return $data;
    }

    /**
     * Updates go through CustomFieldService so the immutable attributes stay
     * immutable and the bags stay consistent with the type.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof CustomField);

        try {
            return app(CustomFieldService::class)->update($record, $data);
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
