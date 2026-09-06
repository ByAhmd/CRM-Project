<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Contracts\OwnedRecord;
use App\Models\Note;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Notes\NoteService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * The notes on a lead, a contact, an account or a deal (decision A-10).
 *
 * One table, one form and one set of actions shared by the four subjects;
 * the subclasses only name the relation and the subject's foreign key. This
 * is the one deliberately non-final class in `app/`: the structural guard
 * allows abstract classes precisely for shared Filament bases like this.
 *
 * Reading follows the subject (`view` on the owner record gates the manager);
 * every write authorises through NotePolicy explicitly, so the actions work
 * on the view page as well as the edit page, and delegates to NoteService.
 * The add and edit forms carry a mentions picker limited to the users the
 * actor may assign the subject to; the service re-checks and notifies them.
 */
abstract class BaseNotesRelationManager extends RelationManager
{
    /** The `notes` column that points at the owner record. */
    protected static string $subjectForeignKey;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('notes.navigation.plural_model');
    }

    public static function getModelLabel(): string
    {
        return __('notes.navigation.model');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('view', $ownerRecord);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                self::bodyField(),

                $this->mentionsField(),

                Toggle::make('is_pinned')
                    ->label(__('notes.fields.is_pinned'))
                    ->default(false),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextEntry::make('body')
                    ->label(__('notes.fields.body'))
                    ->markdown(false)
                    ->extraAttributes(['class' => 'whitespace-pre-line']),

                IconEntry::make('is_pinned')
                    ->label(__('notes.fields.is_pinned'))
                    ->boolean(),

                TextEntry::make('author.name')
                    ->label(__('notes.fields.author'))
                    ->placeholder(__('common.placeholders.empty')),

                TextEntry::make('created_at')
                    ->label(__('notes.fields.created_at'))
                    ->dateTime('Y-m-d H:i'),

                TextEntry::make('edited_at')
                    ->label(__('notes.fields.edited_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder(__('common.placeholders.empty')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // The relations order pinned-first for their other callers; the table drops
            // that order so the column sorts apply and re-adds it as the default below.
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->scopeToReadableSubjects($query
                ->reorder()
                ->withoutGlobalScopes([SoftDeletingScope::class])
                ->where($query->qualifyColumn(static::$subjectForeignKey), $this->getOwnerRecord()->getKey())
                ->with('author')))
            ->columns([
                IconColumn::make('is_pinned')
                    ->label(__('notes.fields.is_pinned'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('body')
                    ->label(__('notes.fields.excerpt'))
                    ->state(fn (Note $record): string => $record->excerpt(120))
                    ->wrap()
                    ->searchable(),

                TextColumn::make('author.name')
                    ->label(__('notes.fields.author'))
                    ->placeholder(__('common.placeholders.empty')),

                TextColumn::make('created_at')
                    ->label(__('notes.fields.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('edited_at')
                    ->label(__('notes.fields.edited_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder(__('common.placeholders.empty')),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('is_pinned')->orderByDesc('created_at'))
            ->filters([
                TrashedFilter::make()->label(__('notes.filters.trashed')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('notes.actions.add'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalHeading(__('notes.actions.add_heading'))
                    ->modalSubmitActionLabel(__('notes.actions.add_submit'))
                    ->successNotificationTitle(__('notes.notifications.added'))
                    ->authorize(fn (): bool => $this->actor()->can('create', [Note::class, $this->getOwnerRecord()]))
                    ->using(fn (array $data): Note => app(NoteService::class)->create(
                        $this->getOwnerRecord(),
                        $this->actor(),
                        (string) ($data['body'] ?? ''),
                        (bool) ($data['is_pinned'] ?? false),
                        self::mentionIds($data),
                    )),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('notes.actions.view'))
                    ->modalHeading(__('notes.actions.view'))
                    ->authorize(fn (Note $record): bool => $this->actor()->can('view', $record)),

                EditAction::make()
                    ->label(__('notes.actions.edit'))
                    ->modalHeading(__('notes.actions.edit'))
                    ->schema([self::bodyField(), $this->mentionsField()])
                    ->successNotificationTitle(__('notes.notifications.updated'))
                    ->hidden(fn (Note $record): bool => $record->trashed())
                    ->authorize(fn (Note $record): bool => $this->actor()->can('update', $record))
                    ->using(fn (Note $record, array $data): Note => app(NoteService::class)->update(
                        $record,
                        $this->actor(),
                        (string) ($data['body'] ?? ''),
                        self::mentionIds($data),
                    )),

                Action::make('togglePin')
                    ->label(fn (Note $record): string => $record->is_pinned ? __('notes.actions.unpin') : __('notes.actions.pin'))
                    ->icon(fn (Note $record): Heroicon => $record->is_pinned ? Heroicon::OutlinedBookmarkSlash : Heroicon::OutlinedBookmark)
                    ->color('gray')
                    ->hidden(fn (Note $record): bool => $record->trashed())
                    ->authorize(fn (Note $record): bool => $this->actor()->can('pin', $record))
                    ->action(function (Note $record): void {
                        $service = app(NoteService::class);
                        $pinning = ! $record->is_pinned;

                        $pinning ? $service->pin($record, $this->actor()) : $service->unpin($record, $this->actor());

                        Notification::make()
                            ->title($pinning ? __('notes.notifications.pinned') : __('notes.notifications.unpinned'))
                            ->success()
                            ->send();
                    }),

                DeleteAction::make()
                    ->label(__('notes.actions.delete'))
                    ->successNotificationTitle(__('notes.notifications.deleted'))
                    ->authorize(fn (Note $record): bool => $this->actor()->can('delete', $record))
                    ->using(function (Note $record): bool {
                        app(NoteService::class)->delete($record, $this->actor());

                        return true;
                    }),

                RestoreAction::make()
                    ->label(__('notes.actions.restore'))
                    ->successNotificationTitle(__('notes.notifications.restored'))
                    ->authorize(fn (Note $record): bool => $this->actor()->can('restore', $record))
                    ->using(function (Note $record): bool {
                        app(NoteService::class)->restore($record, $this->actor());

                        return true;
                    }),
            ])
            ->emptyStateHeading(__('notes.empty.heading'))
            ->emptyStateDescription(__('notes.empty.description'));
    }

    /**
     * Narrows the listing to notes whose subject the actor may read. The lead,
     * contact and deal managers list notes on the owner record only, which the
     * manager gate already covers; the account manager also lists the notes on
     * the account's contacts and deals, which carry their own scope (D-4).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function scopeToReadableSubjects(Builder $query): Builder
    {
        return $query;
    }

    private static function bodyField(): Textarea
    {
        return Textarea::make('body')
            ->label(__('notes.fields.body'))
            ->helperText(__('notes.helpers.body'))
            ->required()
            ->maxLength(Note::MAX_BODY_LENGTH)
            ->rows(5);
    }

    /**
     * Users the actor may mention: their assignment reach for the subject's
     * permission group (D-4), without themselves. Not a column on the note —
     * the service turns the selection into notifications.
     */
    private function mentionsField(): Select
    {
        return Select::make('mentions')
            ->label(__('notes.fields.mentions'))
            ->helperText(__('notes.helpers.mentions'))
            ->multiple()
            ->searchable()
            ->preload()
            ->native(false)
            ->options(function (): array {
                $subject = $this->getOwnerRecord();

                if (! $subject instanceof OwnedRecord) {
                    return [];
                }

                /** @var array<int, string> $options */
                $options = app(RecordVisibilityResolver::class)
                    ->assignableUsers($this->actor(), $subject::permissionGroup())
                    ->whereKeyNot($this->actor()->getKey())
                    ->pluck('name', 'id')
                    ->all();

                return $options;
            })
            ->dehydrated();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private static function mentionIds(array $data): array
    {
        $ids = $data['mentions'] ?? [];

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $id): int => (int) $id, array_filter($ids, static fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))));
    }

    private function actor(): User
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        return $actor;
    }
}
