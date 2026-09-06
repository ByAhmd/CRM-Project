<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activities\Pages;

use App\Exceptions\Activities\InvalidActivityException;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Schemas\ActivityForm;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Logging an activity goes through ActivityRecorder, never Model::create,
 * so the kind is copied from the type, the linked lead and deal are
 * touched and the creation is audited (A-10).
 */
final class CreateActivity extends CreateRecord
{
    protected static string $resource = ActivityResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        try {
            return ActivityForm::log(ActivityForm::subjectFrom($data), $data, $actor);
        } catch (InvalidActivityException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('activities.notifications.logged');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
