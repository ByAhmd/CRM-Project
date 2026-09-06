<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Exceptions\Activities\InvalidActivityException;
use App\Filament\Resources\Activities\Schemas\ActivityForm;
use App\Filament\Resources\Activities\Schemas\ActivityInfolist;
use App\Filament\Resources\Activities\Tables\ActivitiesTable;
use App\Models\Activity;
use App\Models\User;
use App\Services\Activities\ActivitySubject;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The activity timeline on a lead, a contact, an account or a deal
 * (decision A-10): the shared listing, the "log activity" modal and the
 * audited delete, with the owner record as the subject of every new entry.
 *
 * Deliberately abstract — the one exception to the "every class is final"
 * rule (CLAUDE.md section 3): the four subject resources need identical
 * managers that differ only in the relationship they read, so each is a
 * final subclass setting `$relationship` and nothing else. The manager is
 * offered only to users who may list activities AND read the owner record;
 * every activity of a readable record is readable (ActivityPolicy::view).
 */
abstract class BaseActivitiesRelationManager extends RelationManager
{
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('activities.navigation.plural_model');
    }

    public static function getModelLabel(): string
    {
        return __('activities.navigation.model');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->can('viewAny', Activity::class)
            && $user->can('view', $ownerRecord);
    }

    public function form(Schema $schema): Schema
    {
        return ActivityForm::configure($schema, withSubjects: false);
    }

    public function infolist(Schema $schema): Schema
    {
        return ActivityInfolist::configure($schema);
    }

    public function table(Table $table): Table
    {
        return ActivitiesTable::configure($table, withSubject: false)
            ->recordTitleAttribute('subject')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['type', 'lead', 'contact', 'account', 'deal', 'owner']))
            ->headerActions([
                CreateAction::make()
                    ->label(__('activities.actions.log'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalHeading(__('activities.actions.log_heading'))
                    ->modalSubmitActionLabel(__('activities.actions.log_submit'))
                    ->createAnother(false)
                    ->authorize(fn (): bool => auth()->user()?->can('create', Activity::class) ?? false)
                    ->using(fn (array $data): Activity => $this->log($data))
                    ->successNotificationTitle(__('activities.notifications.logged')),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function log(array $data): Activity
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        try {
            return ActivityForm::log(ActivitySubject::for($this->getOwnerRecord()), $data, $actor);
        } catch (InvalidActivityException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
