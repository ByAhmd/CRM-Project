<?php

declare(strict_types=1);

namespace App\Services\Activities;

use App\Exceptions\Activities\InvalidActivityException;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Support\RecordLabel;
use Illuminate\Database\Eloquent\Model;

/**
 * The records an activity is logged against (decision A-10).
 *
 * At least one of the four must be set — an activity about nothing is
 * refused. for() fills the slot matching the record and, so the timeline of
 * the wider record stays complete, also links a contact's account and a
 * deal's account and primary contact.
 */
final readonly class ActivitySubject
{
    public function __construct(
        public ?Lead $lead = null,
        public ?Contact $contact = null,
        public ?Account $account = null,
        public ?Deal $deal = null,
    ) {
        if ($lead === null && $contact === null && $account === null && $deal === null) {
            throw InvalidActivityException::subjectRequired();
        }
    }

    public static function for(Model $record): self
    {
        return match (true) {
            $record instanceof Lead => new self(lead: $record),
            $record instanceof Contact => new self(contact: $record, account: $record->account),
            $record instanceof Account => new self(account: $record),
            $record instanceof Deal => new self(contact: $record->contact, account: $record->account, deal: $record),
            default => throw InvalidActivityException::subjectRequired(),
        };
    }

    /**
     * The foreign keys to store on the activity row.
     *
     * @return array{lead_id: ?int, contact_id: ?int, account_id: ?int, deal_id: ?int}
     */
    public function foreignKeys(): array
    {
        return [
            'lead_id' => self::key($this->lead),
            'contact_id' => self::key($this->contact),
            'account_id' => self::key($this->account),
            'deal_id' => self::key($this->deal),
        ];
    }

    /**
     * Labels of the linked records, for the audit ledger.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return array_filter([
            'lead_name' => $this->lead === null ? null : RecordLabel::of($this->lead),
            'contact_name' => $this->contact === null ? null : RecordLabel::of($this->contact),
            'account_name' => $this->account === null ? null : RecordLabel::of($this->account),
            'deal_title' => $this->deal === null ? null : RecordLabel::of($this->deal),
        ], static fn (?string $label): bool => $label !== null);
    }

    private static function key(?Model $record): ?int
    {
        return $record === null ? null : (int) $record->getKey();
    }
}
