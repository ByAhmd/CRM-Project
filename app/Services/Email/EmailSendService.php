<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\ActivityDirection;
use App\Enums\ActivityKind;
use App\Enums\ActivityLogEvent;
use App\Exceptions\Activities\InvalidActivityException;
use App\Exceptions\Email\EmailNotSendableException;
use App\Mail\CrmMessage;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\User;
use App\Services\Activities\ActivityRecorder;
use App\Services\Activities\ActivitySubject;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Sends a templated email to a contact or a lead (decision D-10).
 *
 * The rules, in order:
 *
 * - the recipient is a contact or a lead, nothing else;
 * - the actor holds `email.send` and reaches the record (the policy's
 *   `sendEmail` verb) — the caller has already checked, the service checks
 *   again so no path can bypass it;
 * - the recipient has an address, otherwise the send is refused before
 *   anything is written;
 * - subject and body are rendered against the recipient once more, so a tag
 *   the user left in the edited text still resolves;
 * - the rendered subject must fit SUBJECT_MAX characters — the width of
 *   `activities.subject` — because a tag such as `{{lead.company_name}}` can
 *   expand a subject that passed the form's limit well past it; the send is
 *   refused with a translated message instead of failing inside the
 *   transaction;
 * - inside one transaction the outbound email activity is recorded through
 *   ActivityRecorder (system type of kind Email) and the send is written to
 *   the audit ledger;
 * - after the commit the message is queued through the application mailer.
 *   Under the log mailer (development, D-10) the message goes to the log and
 *   the activity is still recorded — the record of the conversation does
 *   not depend on the transport.
 */
final class EmailSendService
{
    /** The width of `activities.subject`, which the rendered subject is stored in. */
    public const int SUBJECT_MAX = 200;

    public function __construct(
        private readonly EmailTemplateRenderer $renderer,
        private readonly ActivityRecorder $activities,
        private readonly AuditLogger $audit,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @throws InvalidArgumentException when the recipient is neither a contact nor a lead
     * @throws AuthorizationException when the actor may not email this record
     * @throws EmailNotSendableException when the recipient has no address or the rendered subject exceeds SUBJECT_MAX
     */
    public function send(
        Model $recipient,
        User $actor,
        string $subject,
        string $body,
        string $locale,
        ?EmailTemplate $template = null,
    ): Activity {
        if (! $recipient instanceof Contact && ! $recipient instanceof Lead) {
            throw new InvalidArgumentException(__('email.validation.unsupported_recipient'));
        }

        if (! $actor->can('sendEmail', $recipient)) {
            throw new AuthorizationException;
        }

        $to = trim((string) $recipient->email);

        if ($to === '') {
            throw EmailNotSendableException::noEmail();
        }

        $locale = self::normaliseLocale($locale, $recipient);
        $rendered = $this->renderer->render($subject, $body, $recipient, $actor);

        if (self::subjectIsTooLong($rendered->subject)) {
            throw EmailNotSendableException::subjectTooLong(self::SUBJECT_MAX);
        }

        $type = $this->systemEmailType();
        $recipientName = $recipient->full_name;

        return DB::transaction(function () use ($recipient, $actor, $rendered, $locale, $template, $to, $type, $recipientName): Activity {
            $activity = $this->activities->record(
                ActivitySubject::for($recipient),
                $type,
                $actor,
                $rendered->subject,
                $rendered->body,
                direction: ActivityDirection::Outbound,
                payload: [
                    'template_id' => $template?->getKey(),
                    'template_name' => $template?->display_name,
                    'to' => $to,
                    'locale' => $locale,
                    'sent_by' => $actor->getKey(),
                ],
            );

            $this->audit->record(ActivityLogEvent::EmailSent, $recipient, $actor, [
                'subject_label' => $rendered->subject,
                'to' => $to,
                'template' => $template?->display_name,
                'locale' => $locale,
            ]);

            $message = new CrmMessage(
                messageSubject: $rendered->subject,
                body: $rendered->body,
                recipientName: $recipientName,
                organisationName: $this->settings->organisationName(),
                replyToAddress: $actor->email,
                replyToName: $actor->name,
                locale: $locale,
            );

            DB::afterCommit(static function () use ($to, $recipientName, $message): void {
                Mail::to(new Address($to, $recipientName))->queue($message);
            });

            return $activity;
        });
    }

    /**
     * The language a message to this record is written in unless the sender
     * chooses otherwise: a contact's preferred locale, the application locale
     * for a lead (D-5).
     */
    public static function defaultLocaleFor(Model $recipient): string
    {
        if ($recipient instanceof Contact) {
            return $recipient->preferredLocale();
        }

        return (string) config('app.locale');
    }

    /** Whether a rendered subject exceeds the width it is recorded in; the action shows the same warning in its preview. */
    public static function subjectIsTooLong(string $renderedSubject): bool
    {
        return mb_strlen($renderedSubject) > self::SUBJECT_MAX;
    }

    private static function normaliseLocale(string $locale, Model $recipient): string
    {
        return in_array($locale, (array) config('app.locales'), true) ? $locale : self::defaultLocaleFor($recipient);
    }

    /**
     * The seeded system type of kind Email; the activity is recorded through
     * record() so a deactivated type refuses the send like any other entry.
     */
    private function systemEmailType(): ActivityType
    {
        $type = ActivityType::query()
            ->where('kind', ActivityKind::Email->value)
            ->where('is_system', true)
            ->orderBy('id')
            ->first();

        if ($type === null) {
            throw InvalidActivityException::systemTypeMissing();
        }

        return $type;
    }
}
