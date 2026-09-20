<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Exceptions\Attachments\InvalidAttachmentException;
use App\Filament\Support\LtrText;
use App\Models\Attachment;
use App\Models\User;
use App\Services\Attachments\AttachmentStorage;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * The attachments panel shared by leads, contacts, accounts and deals
 * (module row 13, D-13).
 *
 * Deliberately abstract — the one exception to the "final on every class"
 * rule: the table, the upload action and the record actions are written
 * once here, and each subject resource registers a tiny final subclass that
 * only names the relation. Visibility follows the subject: the panel shows
 * for a user who can view the owner record, and every action authorises
 * through AttachmentPolicy on the server.
 *
 * Uploading is not a CreateAction: Filament's FileUpload parks the file
 * under `tmp/{user id}/` on the private disk — one folder per actor, so the
 * client-controlled path can only ever name the actor's own parked file —
 * and the action hands that temporary file to AttachmentStorage, which
 * sniffs, validates, places and records it, then removes the temporary copy.
 */
abstract class BaseAttachmentsRelationManager extends RelationManager
{
    private const TEMPORARY_DIRECTORY = 'tmp';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('attachments.navigation.plural_model');
    }

    public static function getModelLabel(): string
    {
        return __('attachments.navigation.model');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('uploader'))
            // Phone budget: file name, kind and size stay at every width; the
            // description steps in from `md`, uploader and date from `lg`.
            ->columns([
                LtrText::column(
                    TextColumn::make('original_name')
                        ->label(__('attachments.fields.original_name'))
                        ->icon(fn (Attachment $record): Heroicon => $record->isImage() ? Heroicon::OutlinedPhoto : Heroicon::OutlinedDocument)
                        ->url(fn (Attachment $record): ?string => $this->canDownload($record) ? $record->downloadUrl() : null, shouldOpenInNewTab: true)
                        ->searchable()
                        ->sortable()
                        ->weight('semibold'),
                ),
                TextColumn::make('mime_type')
                    ->label(__('attachments.fields.mime_type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (Attachment $record): string => __('attachments.options.mime.'.$record->mimeFamily())),
                LtrText::column(
                    TextColumn::make('size')
                        ->label(__('attachments.fields.size'))
                        ->formatStateUsing(fn (Attachment $record): string => $record->humanSize())
                        ->sortable(),
                ),
                TextColumn::make('description')
                    ->label(__('attachments.fields.description'))
                    ->placeholder(__('common.placeholders.empty'))
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state !== null && mb_strlen($state) > 60 ? $state : null)
                    ->wrap()
                    ->visibleFrom('md'),
                TextColumn::make('uploader.name')
                    ->label(__('attachments.fields.uploaded_by'))
                    ->placeholder(__('common.placeholders.empty'))
                    ->visibleFrom('lg'),
                LtrText::column(
                    TextColumn::make('created_at')
                        ->label(__('attachments.fields.created_at'))
                        ->dateTime('Y-m-d H:i')
                        ->sortable()
                        ->visibleFrom('lg'),
                ),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TrashedFilter::make()->label(__('attachments.filters.trashed')),
            ])
            ->headerActions([
                $this->uploadAction(),
            ])
            // One three-dot menu per row instead of a wall of links; every
            // action keeps its own authorisation, and the menu hides itself
            // when the policy refuses every action in it.
            ->recordActions([
                ActionGroup::make([
                    $this->downloadAction(),
                    DeleteAction::make()
                        ->label(__('attachments.actions.delete'))
                        ->authorize(fn (Attachment $record): bool => $this->authorised('delete', $record))
                        ->using(function (Attachment $record): bool {
                            app(AttachmentStorage::class)->delete($record, $this->actor());

                            return true;
                        })
                        ->successNotificationTitle(__('attachments.notifications.deleted')),
                    RestoreAction::make()
                        ->label(__('attachments.actions.restore'))
                        ->authorize(fn (Attachment $record): bool => $this->authorised('restore', $record))
                        ->using(function (Attachment $record): bool {
                            app(AttachmentStorage::class)->restore($record, $this->actor());

                            return true;
                        })
                        ->successNotificationTitle(__('attachments.notifications.restored')),
                ])
                    ->label(__('app.actions.row_actions'))
                    ->tooltip(__('app.actions.row_actions')),
            ])
            ->emptyStateHeading(__('attachments.empty.heading'))
            ->emptyStateDescription(__('attachments.empty.description'));
    }

    private function uploadAction(): Action
    {
        return Action::make('upload')
            ->label(__('attachments.actions.upload'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading(__('attachments.actions.upload_heading'))
            ->modalSubmitActionLabel(__('attachments.actions.upload_submit'))
            ->schema([
                FileUpload::make('file')
                    ->label(__('attachments.fields.file'))
                    ->helperText(__('attachments.helpers.file', ['types' => self::allowedTypesLabel(), 'size' => self::maxSizeLabel()]))
                    ->disk(self::diskName())
                    ->directory(fn (): string => $this->temporaryDirectory())
                    ->visibility('private')
                    ->acceptedFileTypes(self::allowedMimeTypes())
                    ->maxSize(self::maxKilobytes())
                    ->storeFileNamesIn('original_name')
                    ->preventFilePathTampering(allowFilePathUsing: fn (string $file): bool => $this->isTemporaryPath($file))
                    ->required(),
                TextInput::make('description')
                    ->label(__('attachments.fields.description'))
                    ->maxLength(255),
            ])
            ->authorize(fn (): bool => $this->canUpload())
            ->action(function (array $data): void {
                $temporaryPath = $data['file'] ?? null;

                if (! is_string($temporaryPath) || ! $this->isTemporaryPath($temporaryPath)) {
                    Notification::make()->title(__('attachments.validation.file_missing'))->danger()->send();

                    return;
                }

                $disk = Storage::disk(self::diskName());
                $originalName = $data['original_name'] ?? null;
                $originalName = is_string($originalName) && trim($originalName) !== ''
                    ? $originalName
                    : basename($temporaryPath);

                try {
                    $upload = new UploadedFile($disk->path($temporaryPath), $originalName, null, null, true);

                    app(AttachmentStorage::class)->store(
                        $upload,
                        $this->getOwnerRecord(),
                        $this->actor(),
                        is_string($data['description'] ?? null) ? $data['description'] : null,
                    );

                    Notification::make()->title(__('attachments.notifications.uploaded'))->success()->send();
                } catch (InvalidAttachmentException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                } finally {
                    $disk->delete($temporaryPath);
                }
            });
    }

    private function downloadAction(): Action
    {
        return Action::make('download')
            ->label(__('attachments.actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->url(fn (Attachment $record): string => $record->downloadUrl(), shouldOpenInNewTab: true)
            ->visible(fn (Attachment $record): bool => ! $record->trashed())
            ->authorize(fn (Attachment $record): bool => $this->authorised('download', $record));
    }

    private function canUpload(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('create', [Attachment::class, $this->getOwnerRecord()]);
    }

    private function canDownload(Attachment $record): bool
    {
        return ! $record->trashed() && $this->authorised('download', $record);
    }

    /**
     * Every row belongs to the owner record, so it is handed to the row as its
     * attachable before AttachmentPolicy reads it: the morph is never queried
     * once per row.
     */
    private function authorised(string $ability, Attachment $record): bool
    {
        if (! $record->relationLoaded('attachable')) {
            $record->setRelation('attachable', $this->getOwnerRecord());
        }

        $user = auth()->user();

        return $user instanceof User && $user->can($ability, $record);
    }

    private function actor(): User
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        return $actor;
    }

    /** Where Filament parks this actor's uploads: `tmp/{user id}`. */
    private function temporaryDirectory(): string
    {
        return self::TEMPORARY_DIRECTORY.'/'.$this->actor()->getKey();
    }

    /**
     * Only a file Filament parked under the actor's own `tmp/{user id}/`
     * folder may be promoted: the field value is client-controlled, so any
     * other path on the disk — another user's parked file included — is refused.
     */
    private function isTemporaryPath(string $path): bool
    {
        $normalised = str_replace('\\', '/', $path);
        $prefix = $this->temporaryDirectory().'/';

        return str_starts_with($normalised, $prefix)
            && ! str_contains($normalised, '..')
            && strlen($normalised) > strlen($prefix);
    }

    private static function diskName(): string
    {
        return (string) config('crm.attachments.disk');
    }

    /**
     * @return list<string>
     */
    private static function allowedMimeTypes(): array
    {
        return array_values((array) config('crm.attachments.allowed_mime_types'));
    }

    private static function maxKilobytes(): int
    {
        return max(1, (int) config('crm.attachments.max_kb'));
    }

    /**
     * The families the allowlist permits, as one translated list — built from
     * the config so the helper cannot drift from what the server accepts.
     */
    private static function allowedTypesLabel(): string
    {
        $families = array_values(array_unique(array_map(
            static fn (string $mime): string => Attachment::familyOf($mime),
            self::allowedMimeTypes(),
        )));

        return implode(
            __('attachments.helpers.list_separator'),
            array_map(static fn (string $family): string => __('attachments.options.mime.'.$family), $families),
        );
    }

    private static function maxSizeLabel(): string
    {
        return Number::fileSize(self::maxKilobytes() * 1024, maxPrecision: 1);
    }
}
