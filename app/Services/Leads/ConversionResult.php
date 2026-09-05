<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;

/**
 * What a lead conversion produced (decisions D-6, D-7): always a contact,
 * optionally an account and a deal, plus the now-frozen lead.
 */
final readonly class ConversionResult
{
    public function __construct(
        public ?Account $account,
        public Contact $contact,
        public ?Deal $deal,
        public Lead $lead,
    ) {}
}
