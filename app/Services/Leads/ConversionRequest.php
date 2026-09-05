<?php

declare(strict_types=1);

namespace App\Services\Leads;

/**
 * What the user asked a lead conversion to produce (decisions D-6, D-7).
 *
 * Built from the convert modal's form data by fromArray(), which normalises
 * the loosely typed Filament state into the shape LeadConversionWorkflow
 * reasons about; the workflow re-validates every rule server-side.
 */
final readonly class ConversionRequest
{
    public const ACCOUNT_NEW = 'new';

    public const ACCOUNT_EXISTING = 'existing';

    public const ACCOUNT_NONE = 'none';

    public const CONTACT_NEW = 'new';

    public const CONTACT_EXISTING = 'existing';

    /**
     * @param  'new'|'existing'|'none'  $accountMode
     * @param  'new'|'existing'  $contactMode
     * @param  ?string  $expectedCloseDate  Y-m-d
     */
    public function __construct(
        public string $accountMode = self::ACCOUNT_NEW,
        public ?string $accountName = null,
        public ?int $accountId = null,
        public string $contactMode = self::CONTACT_NEW,
        public ?int $contactId = null,
        public bool $createDeal = false,
        public ?string $dealTitle = null,
        public ?int $pipelineId = null,
        public ?string $dealAmount = null,
        public ?string $expectedCloseDate = null,
        public ?string $note = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $accountMode = self::string($data['account_mode'] ?? null) ?? self::ACCOUNT_NONE;
        $contactMode = self::string($data['contact_mode'] ?? null) ?? self::CONTACT_NEW;
        $amount = self::string($data['deal_amount'] ?? null);

        return new self(
            accountMode: in_array($accountMode, [self::ACCOUNT_NEW, self::ACCOUNT_EXISTING, self::ACCOUNT_NONE], true) ? $accountMode : self::ACCOUNT_NONE,
            accountName: self::string($data['account_name'] ?? null),
            accountId: self::int($data['account_id'] ?? null),
            contactMode: $contactMode === self::CONTACT_EXISTING ? self::CONTACT_EXISTING : self::CONTACT_NEW,
            contactId: self::int($data['contact_id'] ?? null),
            createDeal: (bool) ($data['create_deal'] ?? false),
            dealTitle: self::string($data['deal_title'] ?? null),
            pipelineId: self::int($data['pipeline_id'] ?? null),
            dealAmount: $amount !== null && is_numeric($amount) ? $amount : null,
            expectedCloseDate: self::string($data['expected_close_date'] ?? null),
            note: self::string($data['note'] ?? null),
        );
    }

    public function wantsNewAccount(): bool
    {
        return $this->accountMode === self::ACCOUNT_NEW;
    }

    public function wantsExistingAccount(): bool
    {
        return $this->accountMode === self::ACCOUNT_EXISTING;
    }

    public function wantsExistingContact(): bool
    {
        return $this->contactMode === self::CONTACT_EXISTING;
    }

    /** A trimmed, non-empty string or null; dates and numbers arrive as strings from the form. */
    private static function string(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
