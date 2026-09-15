<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Exceptions\Activities\InvalidActivityException;
use App\Exceptions\Email\EmailNotSendableException;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\Email\EmailSendService;
use App\Services\Email\EmailTemplateRenderer;
use App\Services\Email\MergeTags;
use App\Support\Notifications\NotificationChannels;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * "Send email" for a contact or a lead (decision D-10), shared by the view
 * pages and the tables.
 *
 * The modal offers the active templates for the record's entity; choosing
 * one — or switching the language — fills the subject and the message with
 * the template rendered against the recipient, and the user may edit both
 * before sending. The preview shows the final text with any tag the user
 * left in the edited text resolved once more, exactly as EmailSendService
 * renders it. The action is disabled, with a tooltip, when the record has no
 * address, and hidden when the policy's `sendEmail` verb refuses.
 */
final class EmailActions
{
    public static function send(): Action
    {
        return Action::make('sendEmail')
            ->label(__('email.actions.send'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('info')
            ->modalHeading(__('email.actions.send_heading'))
            ->modalSubmitActionLabel(__('email.actions.send_submit'))
            ->modalWidth('2xl')
            ->schema(self::form())
            ->fillForm(fn (Model $record): array => self::defaults($record))
            ->authorize(fn (Model $record): bool => auth()->user()?->can('sendEmail', $record) ?? false)
            ->disabled(fn (Model $record): bool => ! self::hasEmail($record))
            ->tooltip(fn (Model $record): ?string => self::hasEmail($record) ? null : __('email.helpers.no_email'))
            ->action(function (Model $record, array $data): void {
                $actor = auth()->user();
                assert($actor instanceof User);

                $templateId = $data['template_id'] ?? null;
                $template = is_numeric($templateId) ? EmailTemplate::query()->find((int) $templateId) : null;

                try {
                    app(EmailSendService::class)->send(
                        $record,
                        $actor,
                        (string) ($data['subject'] ?? ''),
                        (string) ($data['body'] ?? ''),
                        (string) ($data['locale'] ?? ''),
                        $template,
                    );
                } catch (EmailNotSendableException|InvalidActivityException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('email.notifications.sent', ['to' => (string) $record->getAttribute('email')]))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return list<Component>
     */
    private static function form(): array
    {
        return [
            Placeholder::make('mail_warning')
                ->hiddenLabel()
                ->content(__('email.helpers.mail_not_configured'))
                ->visible(fn (): bool => ! NotificationChannels::mailIsConfigured()),

            Select::make('template_id')
                ->label(__('email.fields.template'))
                ->helperText(__('email.helpers.template'))
                ->options(fn (?Model $record): array => $record === null ? [] : self::templateOptions($record))
                ->nullable()
                ->live()
                ->native(false)
                ->afterStateUpdated(function (Set $set, Get $get, mixed $state, ?Model $record): void {
                    self::fillFromTemplate($set, $state, $get('locale'), $record);
                }),

            Select::make('locale')
                ->label(__('email.fields.locale'))
                ->helperText(__('email.helpers.locale'))
                ->options(fn (): array => self::localeOptions())
                ->required()
                ->live()
                ->native(false)
                ->afterStateUpdated(function (Set $set, Get $get, mixed $state, ?Model $record): void {
                    self::fillFromTemplate($set, $get('template_id'), $state, $record);
                }),

            TextInput::make('to')
                ->label(__('email.fields.to'))
                ->extraInputAttributes(['dir' => 'ltr'])
                ->disabled()
                ->dehydrated(false),

            TextInput::make('subject')
                ->label(__('email.fields.subject'))
                ->required()
                ->maxLength(EmailSendService::SUBJECT_MAX)
                ->live(onBlur: true),

            Textarea::make('body')
                ->label(__('email.fields.body'))
                ->rows(10)
                ->required()
                ->maxLength(20000)
                ->live(onBlur: true),

            Placeholder::make('preview')
                ->label(__('email.fields.preview'))
                ->extraAttributes(['style' => 'white-space: pre-line'])
                ->content(fn (Get $get, ?Model $record): string => self::preview((string) $get('subject'), (string) $get('body'), $record)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(Model $record): array
    {
        return [
            'template_id' => null,
            'locale' => EmailSendService::defaultLocaleFor($record),
            'to' => (string) $record->getAttribute('email'),
            'subject' => '',
            'body' => '',
        ];
    }

    /**
     * The active templates offered for the record's entity, in their order.
     *
     * @return array<int, string>
     */
    private static function templateOptions(Model $record): array
    {
        return EmailTemplate::query()
            ->active()
            ->forEntity(MergeTags::entityFor($record))
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (EmailTemplate $template): array => [(int) $template->getKey() => $template->display_name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function localeOptions(): array
    {
        $options = [];

        foreach ((array) config('app.locales') as $locale) {
            $options[(string) $locale] = __('email.options.locale.'.$locale);
        }

        return $options;
    }

    /**
     * Loads the chosen template in the chosen language, rendered against the
     * recipient, into the editable subject and message. Nothing happens
     * without a template, so a hand-written text survives a language switch.
     */
    private static function fillFromTemplate(Set $set, mixed $templateId, mixed $locale, ?Model $record): void
    {
        $actor = auth()->user();

        if ($record === null || ! $actor instanceof User || ! is_numeric($templateId)) {
            return;
        }

        $template = EmailTemplate::query()->active()->forEntity(MergeTags::entityFor($record))->find((int) $templateId);

        if (! $template instanceof EmailTemplate) {
            return;
        }

        $locale = is_string($locale) && in_array($locale, (array) config('app.locales'), true)
            ? $locale
            : EmailSendService::defaultLocaleFor($record);

        $rendered = app(EmailTemplateRenderer::class)->render($template->subjectFor($locale), $template->bodyFor($locale), $record, $actor);

        $set('subject', $rendered->subject);
        $set('body', $rendered->body);
    }

    /**
     * The final text as the service will send it, with any remaining tag
     * resolved, unknown ones called out, and a warning when the resolved
     * subject outgrows the width the service accepts.
     */
    private static function preview(string $subject, string $body, ?Model $record): string
    {
        $actor = auth()->user();

        if ($record === null || ! $actor instanceof User) {
            return '';
        }

        $rendered = app(EmailTemplateRenderer::class)->render($subject, $body, $record, $actor);
        $text = trim($rendered->subject."\n\n".$rendered->body);

        if ($rendered->hasUnknownTags()) {
            $text .= "\n\n".__('email.helpers.unknown_tags', ['tags' => implode(__('common.separators.list'), $rendered->unknownTags)]);
        }

        if (EmailSendService::subjectIsTooLong($rendered->subject)) {
            $text .= "\n\n".__('email.validation.subject_too_long', ['max' => EmailSendService::SUBJECT_MAX]);
        }

        return $text;
    }

    private static function hasEmail(Model $record): bool
    {
        return trim((string) $record->getAttribute('email')) !== '';
    }
}
