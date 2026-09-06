<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A templated message to a contact or a lead (decision D-10), queued and
 * drained by the scheduler in production (D-1).
 *
 * The envelope is sent from the application address (config mail.from) with
 * the sender as reply-to, so an answer reaches the person who wrote it. The
 * body is plain text already rendered by EmailTemplateRenderer; the markdown
 * view escapes it and keeps its line breaks. Every fixed string in the view
 * comes from lang/email.php in the recipient's locale, which the service
 * applies through locale(). Only scalars are carried so the queued payload
 * never depends on a record that may change or disappear before the drain.
 */
final class CrmMessage extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $messageSubject,
        public readonly string $body,
        public readonly string $recipientName,
        public readonly string $organisationName,
        public readonly string $replyToAddress,
        public readonly string $replyToName,
        string $locale,
    ) {
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), (string) config('mail.from.name')),
            replyTo: [new Address($this->replyToAddress, $this->replyToName)],
            subject: $this->messageSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.crm-message',
            with: [
                'recipientName' => $this->recipientName,
                'messageBody' => $this->body,
                'organisationName' => $this->organisationName,
                'replyToName' => $this->replyToName,
            ],
        );
    }
}
