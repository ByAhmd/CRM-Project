<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\LeadPriority;
use App\Enums\LeadStatusKind;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use App\Services\Leads\ConversionRequest;
use App\Services\Leads\ConversionResult;
use App\Services\Leads\LeadConversionWorkflow;
use App\Services\Leads\LeadStatusWorkflow;
use Illuminate\Support\Carbon;

/**
 * Forty leads per batch across every status (decision D-7).
 *
 * Each lead is created in the default status, as the create page does, and
 * then moved only through LeadStatusWorkflow (status log, audit event,
 * qualification note and stamp) and LeadConversionWorkflow (account, contact
 * and deal, Converted status, conversion log and audit). Positions in the
 * batch decide the outcome:
 *
 * - 0–9 stay New; 10–18 reach Contacted; 19–24 are Qualified with a note;
 * - 25–30 are contacted and then marked Unqualified with the reason;
 * - 31–39 are qualified and converted: a new prospect account for a lead
 *   with a company (none for an individual), always a contact, a deal for
 *   six of them. A third of the conversions are run by the owner's team
 *   manager, so the owner receives the conversion notice.
 */
final class DemoLeadsBuilder
{
    public const PER_BATCH = 40;

    /** Lead positions whose conversion also opens a deal. */
    public const CONVERTED_WITH_DEAL = [31, 32, 33, 35, 36, 37];

    private const OWNERS = ['sales_rep', 'sales_rep2', 'sales_rep', 'sales_rep2', 'sales_manager', 'sales_manager2'];

    private const SOURCES = ['Website', 'Referral', 'Social media', 'Campaign', 'Event', 'Cold call', 'Email', 'Advertisement'];

    public function __construct(
        private readonly LeadStatusWorkflow $statuses,
        private readonly LeadConversionWorkflow $conversion,
    ) {}

    public function build(DemoContext $context, int $batch): void
    {
        $status = static fn (LeadStatusKind $kind): LeadStatus => LeadStatus::query()
            ->where('kind', $kind->value)
            ->where('is_active', true)
            ->orderBy('sort')
            ->firstOrFail();

        $new = LeadStatus::query()->where('is_default', true)->where('is_active', true)->firstOrFail();
        $contacted = $status(LeadStatusKind::Working);
        $qualified = $status(LeadStatusKind::Qualified);
        $unqualified = $status(LeadStatusKind::Unqualified);
        $sources = LeadSource::query()->pluck('id', 'name_en')->all();

        for ($i = 0; $i < self::PER_BATCH; $i++) {
            $owner = $context->user(self::OWNERS[$i % count(self::OWNERS)]);
            $created = $context->ago(88 - $i * 2, $batch);
            $lead = $this->create($context, $batch, $i, $owner, $new, $sources, $created);
            $arabic = $this->isArabic($i);

            if ($i >= 10) {
                $context->as($owner, $created->copy()->addDays($i >= 31 ? 1 : 2), fn (): Lead => $this->statuses->transition($lead, $contacted, $owner));
            }

            if (($i >= 19 && $i <= 24) || $i >= 31) {
                $note = $arabic
                    ? 'الميزانية معتمدة وصاحب القرار شارك في الاجتماع؛ الاحتياج واضح والتنفيذ خلال الربع القادم.'
                    : 'Budget approved and the decision maker joined the call; clear need, rollout planned next quarter.';

                $context->as($owner, $created->copy()->addDays($i >= 31 ? 3 : 5), fn (): Lead => $this->statuses->transition($lead, $qualified, $owner, $note));
            }

            if ($i >= 25 && $i <= 30) {
                $note = $arabic ? 'لا توجد ميزانية هذا العام، وسيُعاد التواصل لاحقًا.' : 'No budget this year; revisit next fiscal year.';

                $context->as($owner, $created->copy()->addDays(4), fn (): Lead => $this->statuses->transition($lead, $unqualified, $owner, $note));
            }

            if ($i >= 31) {
                $this->convert($context, $batch, $i, $lead, $owner, $created->copy()->addDays(6));
            }
        }
    }

    /**
     * @param  array<string, int>  $sources
     */
    private function create(DemoContext $context, int $batch, int $i, User $owner, LeadStatus $new, array $sources, Carbon $created): Lead
    {
        $people = DemoDataset::people();
        $companies = DemoDataset::leadCompanies();
        $cities = DemoDataset::cities();
        $titles = DemoDataset::jobTitles();
        $company = $i % 5 === 4 ? null : $companies[($i - intdiv($i, 5)) % count($companies)];
        $arabic = $this->isArabic($i);
        [$first, $firstAscii] = $people[$arabic ? 'ar_first' : 'en_first'][($i * 7 + 3) % 10];
        [$last, $lastAscii] = $people[$arabic ? 'ar_last' : 'en_last'][($i * 3 + 5) % 10];
        $city = $cities[$i % count($cities)];
        $domain = $company === null ? 'personal-mail.example' : $company['domain'].DemoAccountsBuilder::domainSuffix($batch).'.example';

        $lead = $context->as($owner, $created, fn (): Lead => Lead::query()->create([
            'first_name' => $first,
            'last_name' => $last,
            'company_name' => $company === null ? null : DemoAccountsBuilder::suffixed($company['name'], $batch),
            'job_title' => $company === null ? null : $titles[$arabic ? 'ar' : 'en'][$i % 6],
            'email' => sprintf('%s.%s.%d@%s', $firstAscii, $lastAscii, $batch * self::PER_BATCH + $i + 1, $domain),
            'phone' => DemoAccountsBuilder::phone(2000 + $batch * 97 + $i),
            'website' => $company === null ? null : 'https://'.$domain,
            'address_line' => null,
            'city' => $arabic ? $city['ar'] : $city['en'],
            'region' => $arabic ? $city['region_ar'] : $city['region_en'],
            'country' => 'SA',
            'postal_code' => $city['postal'],
            'lead_source_id' => $sources[self::SOURCES[$i % count(self::SOURCES)]] ?? null,
            'lead_status_id' => $new->getKey(),
            'owner_id' => $owner->getKey(),
            'priority' => [LeadPriority::Medium, LeadPriority::High, LeadPriority::Low, LeadPriority::Medium, LeadPriority::High][$i % 5],
            'description' => $arabic ? 'مهتم بتنظيم متابعة العملاء وتقارير المبيعات.' : 'Interested in structured follow-ups and sales reporting.',
            'created_by' => $owner->getKey(),
        ]));

        $context->leads[] = $lead;
        $context->record('leads', (int) $lead->getKey());

        return $lead;
    }

    private function convert(DemoContext $context, int $batch, int $i, Lead $lead, User $owner, Carbon $moment): void
    {
        $actor = $i % 3 === 0 ? $context->managerOf($owner) : $owner;
        $withDeal = in_array($i, self::CONVERTED_WITH_DEAL, true);
        $arabic = $this->isArabic($i);
        $titles = DemoDataset::dealTitles()[$arabic ? 'ar' : 'en'];

        $request = new ConversionRequest(
            accountMode: $lead->company_name === null ? ConversionRequest::ACCOUNT_NONE : ConversionRequest::ACCOUNT_NEW,
            contactMode: ConversionRequest::CONTACT_NEW,
            createDeal: $withDeal,
            dealTitle: $withDeal ? $titles[$i % count($titles)].' - '.($lead->company_name ?? $lead->full_name) : null,
            dealAmount: $withDeal ? number_format(18000 + ($i * 2750) % 42000, 2, '.', '') : null,
            expectedCloseDate: $withDeal ? $context->now->copy()->addDays(10 + $i)->toDateString() : null,
            note: $arabic ? 'تم التحويل بعد اعتماد العرض المبدئي.' : 'Converted after the initial proposal was accepted.',
        );

        /** @var ConversionResult $result */
        $result = $context->as($actor, $moment, fn (): ConversionResult => $this->conversion->convert($lead, $request, $actor));

        if ($result->account !== null) {
            $context->accounts[] = $result->account;
            $context->contactsByAccount[(int) $result->account->getKey()][] = $result->contact;
            $context->record('accounts', (int) $result->account->getKey());
        }

        $context->contacts[] = $result->contact;
        $context->record('contacts', (int) $result->contact->getKey());

        if ($result->deal !== null) {
            $context->convertedDeals[$batch * self::PER_BATCH + $i] = $result->deal;
            $context->record('deals', (int) $result->deal->getKey());
        }
    }

    /** Leads alternate between Arabic and English speakers; a company's language wins. */
    private function isArabic(int $i): bool
    {
        if ($i % 5 === 4) {
            return $i % 2 === 0;
        }

        $companies = DemoDataset::leadCompanies();

        return $companies[($i - intdiv($i, 5)) % count($companies)]['arabic'];
    }
}
